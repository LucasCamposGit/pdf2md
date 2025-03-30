<?php

namespace app\core\model;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Class OcrModel (Fat Model Example)
 *
 * Handles the business logic AND direct interaction with the Mistral OCR API.
 */
class OcrModel
{
    // --- API/Client Details Moved Here ---
    private Client $client;
    private string $api_key;
    private string $api_base_uri;
    private ?string $last_error = null;

    private const DEFAULT_MISTRAL_API_BASE_URI = 'https://api.mistral.ai';
    private const DEFAULT_REQUEST_TIMEOUT = 60.0; // seconds
    private const DEFAULT_OCR_MODEL = 'mistral-ocr-latest';
    private const DEFAULT_SIGNED_URL_EXPIRY = 120; // Increased expiry


    /**
     * Constructor requires API key directly now.
     */
    public function __construct(
        string $api_key,
        ?string $api_base_uri = null,
        ?Client $client = null // Allow injecting a client for testing
    ) {
        if (empty($api_key)) {
            throw new InvalidArgumentException('Mistral API key cannot be empty.');
        }
        $this->api_key = $api_key;
        $this->api_base_uri = rtrim($api_base_uri ?? self::DEFAULT_MISTRAL_API_BASE_URI, '/');

        // Use provided client or create a new one
        $this->client = $client ?? new Client([
            'base_uri' => $this->api_base_uri,
            'timeout'  => self::DEFAULT_REQUEST_TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept'        => 'application/json',
            ],
        ]);
    }

    /**
     * Processes PDF content (as a string or stream) using Mistral OCR and returns combined markdown.
     *
     * @param string|resource $pdf_content The raw PDF content or a file stream.
     * @param string $original_filename A filename for context (used in upload).
     * @param string $ocr_model Optional OCR model override.
     * @param int $signed_url_expiry Optional expiry override.
     * @return string The combined markdown content.
     * @throws RuntimeException If any step of the OCR process fails.
     */
    public function processPdfContentToMarkdown(
        $pdf_content,
        string $original_filename = 'upload.pdf',
        string $ocr_model = self::DEFAULT_OCR_MODEL,
        int $signed_url_expiry = self::DEFAULT_SIGNED_URL_EXPIRY
    ): string {
        $temp_file_path = null; // For cleanup if we create a temp file
        $this->last_error = null; // Reset error for this operation

        try {
            // --- Step 0: Prepare Upload Source ---
            if (is_string($pdf_content)) {
                $temp_file_path = tempnam(sys_get_temp_dir(), 'ocr_upload_');
                if ($temp_file_path === false || file_put_contents($temp_file_path, $pdf_content) === false) {
                    throw new RuntimeException("Failed to create temporary file for OCR upload.");
                }
                $upload_source = $temp_file_path;
            } elseif (is_resource($pdf_content)) {
                $upload_source = $pdf_content;
            } else {
                throw new InvalidArgumentException("Invalid pdf_content provided. Must be string or resource.");
            }

            // --- Step 1: Upload File (Direct API Call) ---
            $uploaded_file_id = $this->uploadFileDirect($upload_source, $original_filename);

            // --- Step 2: Get Signed URL (Direct API Call) ---
            $signed_url = $this->getSignedUrlDirect($uploaded_file_id, $signed_url_expiry);

            // --- Step 3: Trigger OCR (Direct API Call) ---
            $ocr_response_data = $this->triggerOcrProcessingDirect($signed_url, $ocr_model);

            // --- Step 4: Format OCR Response ---
            $combined_markdown_output = $this->formatOcrResponse($ocr_response_data);

            return $combined_markdown_output;
        } catch (Throwable $e) {
            // Log the specific error
            error_log("OcrModel Error: Failed during OCR processing for {$original_filename}. Reason: " . $e->getMessage() . ($this->last_error ? " | Detail: " . $this->last_error : ""));
            // Throw a general exception upwards
            throw new RuntimeException(
                "Failed to process PDF content to markdown for {$original_filename}. " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            // Clean up temporary file if we created one
            if ($temp_file_path && file_exists($temp_file_path)) {
                unlink($temp_file_path);
            }
        }
    }

    // ===========================================
    // Direct API Interaction Methods (Private)
    // ===========================================

    /**
     * Uploads a file stream or path directly to the Mistral API.
     * @param string|resource $file_content File path or resource stream.
     * @param string $filename The name of the file being uploaded.
     * @return string The uploaded file ID.
     * @throws RequestException | RuntimeException
     */
    private function uploadFileDirect($file_content, string $filename): string
    {
        try {
            $file_resource = is_resource($file_content) ? $file_content : Utils::tryFopen($file_content, 'r');

            // <<< ADD DEBUGGING HERE >>>
            if (!$file_resource) {
                error_log("OcrModel Error: Failed to open file resource for: " . (is_string($file_content) ? $file_content : 'resource'));
                throw new RuntimeException("Failed to create file resource for upload.");
            }
            if (is_string($file_content)) { // If it was a path, check file size
                clearstatcache(); // Clear file status cache
                $fileSize = filesize($file_content);
                error_log("OcrModel Debug: Temp file path: " . $file_content . " | Size: " . $fileSize . " bytes.");
                if ($fileSize === 0) {
                    error_log("OcrModel Error: Temporary file is empty.");
                    // Optionally throw exception here if empty files are invalid
                }
                // You could also check read permissions is_readable($file_content)
                if (!is_readable($file_content)) {
                    error_log("OcrModel Error: Temporary file is not readable: " . $file_content);
                    // Optionally throw exception
                }
            }
            error_log("OcrModel Debug: Attempting to upload filename: " . $filename);
            // <<< END DEBUGGING >>>



            $response = $this->client->post('/v1/files', [
                'multipart' => [
                    ['name' => 'file', 'contents' => $file_resource, 'filename' => $filename],
                    ['name' => 'purpose', 'contents' => 'ocr']
                ]
            ]);

            $bodyContents = $response->getBody()->getContents();
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->last_error = "Upload API returned status {$statusCode}. Body: {$bodyContents}";
                throw new RuntimeException("File upload failed with status: {$statusCode}");
            }
            $upload_data = json_decode($bodyContents);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->last_error = "Failed to decode JSON from upload response. Body: {$bodyContents}";
                throw new RuntimeException("Invalid JSON received from file upload API.");
            }
            if (!isset($upload_data->id)) {
                $this->last_error = "File ID missing in upload response. Body: {$bodyContents}";
                throw new RuntimeException("File ID not found in upload response.");
            }
            if (!is_resource($file_content) && is_resource($file_resource)) {
                fclose($file_resource);
            } // Close if opened here
            return $upload_data->id;
        } catch (RequestException $e) {
            $this->handleRequestException($e, "uploadFileDirect: {$filename}");
            // Re-throw the original exception to preserve stack trace and type
            throw new RuntimeException("Failed to upload file due to API request error: " . $e->getMessage(), $e->getCode(), $e);
        } catch (Throwable $e) {
            // Catch other potential errors (e.g., file system issues, JSON errors)
            $this->handleGenericException($e, "uploadFileDirect: {$filename}");
            // Wrap in a RuntimeException for consistent error handling upstream
            throw new RuntimeException("An unexpected error occurred during file upload: " . $e->getMessage(), $e->getCode(), $e);
        } finally {
            // Ensure file handle is closed if we opened it and it wasn't the original input
            if (!is_resource($file_content) && isset($file_resource) && is_resource($file_resource)) {
                @fclose($file_resource); // Use @ to suppress errors if already closed
            }
        }
    }

    /**
     * Gets a signed URL directly from the Mistral API.
     * @throws RequestException | RuntimeException
     */
    private function getSignedUrlDirect(string $file_id, int $expiry_seconds): string
    {
        try {
            $response = $this->client->get("/v1/files/{$file_id}/url", ['query' => ['expiry' => $expiry_seconds]]);

            $bodyContents = $response->getBody()->getContents();
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->last_error = "Get Signed URL API returned status {$statusCode} for file {$file_id}. Body: {$bodyContents}";
                throw new RuntimeException("Getting signed URL failed for file {$file_id} with status: {$statusCode}");
            }
            $signed_url_data = json_decode($bodyContents);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->last_error = "Failed to decode JSON from signed URL response for file {$file_id}. Body: {$bodyContents}";
                throw new RuntimeException("Invalid JSON received from signed URL API.");
            }
            if (!isset($signed_url_data->url)) {
                $this->last_error = "Signed URL missing in response for file {$file_id}. Body: {$bodyContents}";
                throw new RuntimeException("Signed URL not found for file {$file_id}.");
            }
            return $signed_url_data->url;
        } catch (RequestException $e) {
            $this->handleRequestException($e, "getSignedUrlDirect for file: {$file_id}");
            throw new RuntimeException("Failed to get signed URL due to API request error: " . $e->getMessage(), $e->getCode(), $e);
        } catch (Throwable $e) {
            $this->handleGenericException($e, "getSignedUrlDirect for file: {$file_id}");
            throw new RuntimeException("An unexpected error occurred while getting the signed URL: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Triggers the OCR processing job directly via the Mistral API.
     * @throws RequestException | RuntimeException
     */
    private function triggerOcrProcessingDirect(string $signed_url, string $model): object
    {
        try {
            $response = $this->client->post('/v1/ocr', [
                'json' => [
                    'model' => $model,
                    'document' => ['type' => 'document_url', 'document_url' => $signed_url],
                    'include_image_base64' => true // Assuming this is desired
                ]
            ]);

            $bodyContents = $response->getBody()->getContents();
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->last_error = "OCR Processing API returned status {$statusCode}. Body: {$bodyContents}";
                throw new RuntimeException("OCR processing request failed with status: {$statusCode}");
            }
            $response_data = json_decode($bodyContents);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->last_error = "Failed to decode JSON from OCR response. Body: {$bodyContents}";
                throw new RuntimeException("Invalid JSON received from OCR process API.");
            }
            if (!is_object($response_data)) {
                $this->last_error = "OCR response was not a valid JSON object. Body: {$bodyContents}";
                throw new RuntimeException("Invalid response format from OCR process.");
            }
            return $response_data;
        } catch (RequestException $e) {
            $this->handleRequestException($e, "triggerOcrProcessingDirect");
            throw new RuntimeException("Failed to trigger OCR processing due to API request error: " . $e->getMessage(), $e->getCode(), $e);
        } catch (Throwable $e) {
            $this->handleGenericException($e, "triggerOcrProcessingDirect");
            throw new RuntimeException("An unexpected error occurred during OCR processing trigger: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    // ===========================================
    // Formatting Methods (Correction Applied)
    // ===========================================
    private function formatOcrResponse(object $ocr_response): string
    {
        $markdowns = [];
        if (isset($ocr_response->pages) && is_array($ocr_response->pages)) {
            foreach ($ocr_response->pages as $page_index => $page) {
                if (!is_object($page)) continue; // Skip invalid page data

                $image_data = [];
                if (isset($page->images) && is_array($page->images)) {
                    foreach ($page->images as $img) {
                        if (is_object($img) && isset($img->id) && is_string($img->id) && isset($img->image_base64) && is_string($img->image_base64)) {
                            $image_data[$img->id] = $img->image_base64;
                        }
                    }
                }

                // Use empty string if markdown is not set or not a string
                $page_markdown = (isset($page->markdown) && is_string($page->markdown)) ? $page->markdown : '';

                // Process images only if there's markdown content
                if (!empty($page_markdown) && !empty($image_data)) {
                    $page_markdown = $this->replaceImagesInMarkdown($page_markdown, $image_data);
                }
                $markdowns[] = $page_markdown;
            }
        }
        // Join pages with a clear separator
        return implode("\n\n---\n\n", $markdowns); // Using --- as a page separator
    }


    private function replaceImagesInMarkdown(string $markdown_str, array $images_dict): string
    {
        foreach ($images_dict as $img_name => $base64_str) {
            // Ensure img_name is a non-empty string before using preg_quote
            if (!is_string($img_name) || $img_name === '') continue;
            if (!is_string($base64_str) || empty($base64_str)) continue; // Skip invalid base64 data

            // Prepare base64 data URI
            $data_uri_prefix = 'data:image/jpeg;base64,'; // Assuming JPEG, adjust if needed
            // Simple check if prefix exists, add if not
            if (strpos($base64_str, 'data:image/') !== 0) {
                $base64_str = $data_uri_prefix . $base64_str;
            }

            // **CORRECTED PATTERN:** Escape literal parentheses `(` and `)`
            // Use preg_quote for the dynamic parts ($img_name)
            // Escape the literal parentheses with \\ because they are inside a PHP string
            $placeholder_pattern = "!\[" . preg_quote($img_name, '/') . "\]\\(" . preg_quote($img_name, '/') . "\\)";

            // Prepare replacement - ensure image name in alt text is properly escaped for HTML context if needed later
            $replacement = "![" . htmlspecialchars($img_name, ENT_QUOTES) . "](" . $base64_str . ")";

            // Perform replacement
            // Use @ to suppress potential warnings if the pattern is somehow still invalid,
            // though the correction should prevent the compilation error.
            $new_markdown_str = @preg_replace('/' . $placeholder_pattern . '/', $replacement, $markdown_str, 1);

            // Check if preg_replace encountered an error (other than compilation)
            if ($new_markdown_str === null && preg_last_error() !== PREG_NO_ERROR) {
                error_log("OcrModel Warning: preg_replace failed for image '{$img_name}'. Error: " . preg_last_error_msg());
                // Optionally decide whether to continue with the original string or stop
                // continue; // Skip this image if replacement failed
                // For now, we'll keep the potentially unmodified string if an error occurs
                $new_markdown_str = $markdown_str; // Revert to original if replacement failed
            }
            $markdown_str = $new_markdown_str;
        }
        return $markdown_str;
    }


    // ===========================================
    // Error Handling Helper Methods (Private)
    // ===========================================
    private function handleRequestException(RequestException $e, string $context): void
    {
        $error_message = "Model Error: RequestException during [{$context}]: " . $e->getMessage();
        if ($e->hasResponse()) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            // Avoid logging excessively large bodies
            $bodySnippet = strlen($body) > 500 ? substr($body, 0, 500) . '...' : $body;
            $error_message .= " | Status: {$statusCode} | Response: {$bodySnippet}";
        } else {
            $error_message .= " | No response received.";
        }
        $this->last_error = $error_message; // Store the detailed error message
        error_log($error_message); // Log the detailed error
    }

    private function handleGenericException(Throwable $e, string $context): void
    {
        $error_message = "Model Error: " . get_class($e) . " during [{$context}]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
        $this->last_error = $error_message; // Store the error message
        error_log($error_message); // Log the full error details
        // Optionally log the stack trace for deeper debugging
        // error_log("Stack Trace:\n" . $e->getTraceAsString());
    }

    /**
     * Returns the last error message recorded during an operation.
     *
     * @return ?string The last error message, or null if no error occurred.
     */
    public function getLastError(): ?string
    {
        return $this->last_error;
    }
}
