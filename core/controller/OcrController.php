<?php

namespace app\core\controller;

use app\core\request\Request;
use app\core\response\Response; // Use your Response class
use app\core\model\OcrModel;
// use app\core\auth\Auth; // Optional: If authentication is needed
use Throwable;

/**
 * Class OcrController
 *
 * Handles HTTP requests related to OCR processing.
 */
class OcrController
{
    private Request $request;
    private Response $response;
    private OcrModel $ocr_model;
    // private ?Auth $auth; // Make Auth optional if not always needed

    // Inject dependencies
    public function __construct(
        Request $request,
        Response $response,
        OcrModel $ocr_model,
        // ?Auth $auth = null // Allow Auth to be optional
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->ocr_model = $ocr_model;
        // $this->auth = $auth;
    }

    /**
     * Handles POST requests to process an uploaded PDF file for OCR.
     * Expects PDF data in the request body.
     * Responds with Markdown content.
     */
    public function handleProcessPdf(): void
    {
        // Optional: Authentication check
        // if ($this->auth) {
        //     $this->auth->authenticate(); // Assuming authenticate() throws on failure
        // }

        try {
            // Get raw PDF content from the request body
            $pdf_content = $this->request->getRawRequestData();
            if (empty($pdf_content)) {
                $this->response->setError(400, 'No PDF data received in request body.')->send();
                return; // Exit after sending response
            }

            // Extract a filename if available (e.g., from a header or query param), otherwise use default
            // This depends on how the client sends the file info. Example:
            $original_filename = $this->request->getSpecificHeader('X-Filename') ?? 'uploaded_document.pdf';
            // Sanitize filename potentially

             // *** ADD FILENAME SANITIZATION HERE ***
            // 1. Apply basename to remove potential path traversal attempts
            $unsafe_filename = basename($original_filename);
            // 2. Convert to UTF-8 defensively (if mbstring exists)
            if (function_exists('mb_convert_encoding')) {
                 $unsafe_filename = mb_convert_encoding($unsafe_filename, 'UTF-8', 'UTF-8');
            }
            // 3. Remove characters known to cause issues:
            //    - Control characters (ASCII 0-31, 127)
            //    - Characters potentially problematic in filenames or headers: / \ : * ? " < > |
            //    - Replace Unicode Replacement Character (often from bad encoding)
            $sanitized_filename = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]/u', '_', $unsafe_filename);
            // 4. Optional: Limit length
            $sanitized_filename = substr($sanitized_filename, 0, 200); // Limit length reasonably
             // 5. Ensure it's not empty after sanitization
             if (empty($sanitized_filename) || $sanitized_filename === '.pdf') {
                 $sanitized_filename = 'sanitized_upload.pdf';
             }
            // Use the sanitized filename from now on
            $filename = $sanitized_filename;
            // *** END SANITIZATION ***



            // Call the model to process the content
            $markdown_result = $this->ocr_model->processPdfContentToMarkdown(
                $pdf_content,
                $filename // Pass filename for context
            );

            // Success: Send the markdown response
            $this->response
                ->setStatusCode(200)
                ->setHeader('Content-Type', 'text/markdown; charset=utf-8')
                // Optionally add filename header for download
                ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '.md"')
                ->setBody($markdown_result)
                ->send();
        } catch (\InvalidArgumentException $e) {
            error_log("OcrController Error: Invalid argument - " . $e->getMessage());
            $this->response->setError(400, 'Bad Request: ' . $e->getMessage())->send();
        } catch (\RuntimeException $e) {
            // Runtime exceptions often indicate processing failures (logged in model/gateway)
            error_log("OcrController Error: Runtime exception - " . $e->getMessage());
            $this->response->setError(500, 'Failed to process PDF: ' . $e->getMessage())->send();
        } catch (Throwable $e) {
            // Catch any other unexpected errors
            // Log the full error for debugging
            error_log("OcrController Error: Unexpected error - " . get_class($e) . ": " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            // Send a 500 error including the specific exception message
            $this->response->setError(500, 'An unexpected server error occurred: ' . $e->getMessage())->send();
        }
    }

    // Add other methods as needed (e.g., handleGetStatus if OCR is async)
}
