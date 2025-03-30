<?php

namespace app\core\response;

use InvalidArgumentException; // For validation errors
use JsonException; // To catch JSON encoding errors

/**
 * Class Response
 *
 * Represents an HTTP response to be sent back to the client.
 * Designed to be instantiated and configured before sending.
 */
class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private string $body = '';

    /**
     * Constructor.
     * Optionally set the initial status code.
     *
     * @param int $statusCode Initial HTTP status code.
     */
    public function __construct(int $statusCode = 200)
    {
        $this->setStatusCode($statusCode);
        // Set a default content type, can be overridden later
        $this->setHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Sets the HTTP status code for the response.
     *
     * @param int $code The HTTP status code (e.g., 200, 404, 500).
     * @return $this Allows for method chaining.
     * @throws InvalidArgumentException If the status code is invalid.
     */
    public function setStatusCode(int $code): self
    {
        // Basic validation, you might want more specific ranges
        if ($code < 100 || $code > 599) {
            // Log invalid codes but maybe allow them if needed for specific cases?
            // For strictness, throwing is better:
             throw new InvalidArgumentException("Invalid HTTP status code: {$code}");
        }
        $this->statusCode = $code;
        // Ensure http_response_code is set immediately if possible,
        // although send() is the final authority.
        // http_response_code($this->statusCode); // Moved to send() for better control
        return $this; // Return instance for chaining
    }

    /**
     * Adds or replaces an HTTP header.
     *
     * @param string $name The header name (e.g., 'Content-Type', 'Location').
     * @param string $value The header value.
     * @return $this Allows for method chaining.
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this; // Return instance for chaining
    }

    /**
     * Sets the response body content.
     *
     * @param string $body The content for the response body.
     * @return $this Allows for method chaining.
     */
    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this; // Return instance for chaining
    }

    /**
     * Sets the response body to JSON encoded data.
     * Automatically sets the Content-Type header to application/json.
     *
     * @param mixed $data The data to encode as JSON.
     * @param int $jsonFlags JSON encoding options (e.g., JSON_PRETTY_PRINT).
     * @return $this Allows for method chaining.
     * @throws \JsonException If JSON encoding fails.
     */
    public function setJsonBody(mixed $data, int $jsonFlags = 0): self
    {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        // Add JSON_INVALID_UTF8_SUBSTITUTE flag (PHP >= 7.2) as a first line of defense
        // This replaces invalid UTF-8 sequences with a Unicode replacement character (U+FFFD)
        // JSON_THROW_ON_ERROR is still essential to catch other encoding issues.
        $this->body = json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | $jsonFlags);
        return $this; // Return instance for chaining
    }

    /**
     * Convenience method to configure the response as a standard JSON error.
     * Sets status code, JSON body, and Content-Type.
     * Attempts to sanitize the message if direct JSON encoding fails.
     *
     * @param int $statusCode The HTTP error status code (e.g., 400, 404, 500).
     * @param string $message The error message.
     * @param ?array $additionalData Optional additional data to include in the error object.
     * @return $this Allows for method chaining.
     */
    public function setError(int $statusCode, string $message, ?array $additionalData = null): self
    {
        // Basic validation for client/server error codes
        if ($statusCode < 400 || $statusCode > 599) {
            error_log("Warning: Setting non-error status code ({$statusCode}) via setError method with message: {$message}");
             // Allow setting it, but log a warning
        }

        // Store original message in case sanitization is needed
        $originalMessage = $message;

        $errorPayload = [
            'error' => [
                'code' => $statusCode,
                'message' => $originalMessage, // Use original first
            ]
        ];

        if ($additionalData !== null) {
            // Be cautious merging additional data, it could also cause encoding issues
            // For simplicity, only merge if it's known to be safe or sanitize it too
             try {
                 // Test encode just the additional data first? Might be overkill.
                 // Let's try merging directly and rely on the outer catch.
                 $errorPayload['error'] = array_merge($errorPayload['error'], $additionalData);
             } catch (\Throwable $t) { // Catch potential merge issues if $additionalData is weird
                  error_log("Warning: Could not merge additional data into error payload. Skipping. Error: " . $t->getMessage());
             }
        }

        try {
            // Attempt to set the JSON body with the original payload
            $this->setStatusCode($statusCode);
            // Use JSON_PRETTY_PRINT for better readability of errors during debugging
            // JSON_INVALID_UTF8_SUBSTITUTE is now added in setJsonBody
            $this->setJsonBody($errorPayload, JSON_PRETTY_PRINT);

        } catch (JsonException $e) {
            // --- Fallback if initial JSON encoding fails ---
            error_log("Warning: Failed to JSON encode original error message. Attempting sanitization. Initial JsonException: " . $e->getMessage());

            try {
                // Attempt to sanitize the original message using mb_convert_encoding
                // Requires the mbstring PHP extension to be enabled
                if (function_exists('mb_convert_encoding')) {
                     $sanitizedMessage = mb_convert_encoding($originalMessage, 'UTF-8', 'UTF-8');
                } else {
                     // Basic fallback if mbstring is not available
                     $sanitizedMessage = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '?', $originalMessage); // Replace control/non-ASCII chars
                     error_log("Warning: mbstring extension not available. Using basic preg_replace for sanitization.");
                }


                $fallbackPayload = [
                    'error' => [
                        'code' => $statusCode, // Keep the original intended status code
                        // Provide the sanitized message and indicate it was modified
                        'message' => "Sanitized Error: " . $sanitizedMessage . " (Note: Original message may have contained invalid characters)",
                         'original_encoding_error' => $e->getMessage() // Include the specific JSON error
                    ]
                ];

                // Retry setting the JSON body with the sanitized payload
                $this->setStatusCode($statusCode); // Ensure original code is set
                // Add JSON_INVALID_UTF8_SUBSTITUTE here too just in case mbstring failed somehow
                $this->setJsonBody($fallbackPayload, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

            } catch (JsonException $e2) {
                // --- Ultimate Fallback if even sanitization fails ---
                error_log("Critical Error: Failed to encode even sanitized/fallback error response as JSON. JsonException: " . $e2->getMessage());
                // Set a definite 500 status code here
                $this->setStatusCode(500);
                $this->setHeader('Content-Type', 'application/json; charset=utf-8');
                // Provide a safe, generic JSON error message
                $this->setBody('{"error":{"code":500,"message":"Internal Server Error: Failed to encode error details due to nested encoding issues."}}');
            } catch (\Throwable $t) {
                  // Catch any other unexpected error during fallback creation
                  error_log("Critical Error: Unexpected exception while creating fallback error response: " . $t->getMessage());
                  $this->setStatusCode(500);
                  $this->setHeader('Content-Type', 'application/json; charset=utf-8');
                  $this->setBody('{"error":{"code":500,"message":"Internal Server Error: Exception occurred during error handling."}}');
            }
        } catch (\Throwable $t) {
             // Catch any other unexpected error during the initial try block
             error_log("Critical Error: Unexpected exception while setting error response: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine());
             // Attempt to send a very basic error if possible
             if (!headers_sent()) {
                  $this->setStatusCode(500);
                  $this->setHeader('Content-Type', 'application/json; charset=utf-8');
                  $this->setBody('{"error":{"code":500,"message":"Internal Server Error: Unexpected failure during error response generation."}}');
             }
             // We might need to just exit here if things are really broken
             // $this->send(); // Avoid calling send() within setError itself usually
         }

         return $this; // Return instance for chaining
    }


    /**
     * Sends the configured HTTP response (status code, headers, body)
     * and terminates script execution.
     */
    public function send(): void
    {
        // Check if headers have already been sent (prevents errors/warnings)
        if (headers_sent($file, $line)) {
            // Log this critical error, as the response cannot be properly sent.
             // Avoid echoing anything here as it will corrupt output further.
            error_log("Error: Headers already sent in {$file} on line {$line}. Cannot send new response headers or status code.");
            // Exit here because we can't proceed reliably.
             // Consider not exiting if partial content might be acceptable in some contexts,
             // but for API responses, exiting is usually safer.
             exit; // Stop script execution
        }

        // Set HTTP status code using the object's property
        // This is the definitive point where the status code is set before headers.
        http_response_code($this->statusCode);

        // Set headers using the object's property
        foreach ($this->headers as $name => $value) {
            // Consider adding a check for valid header names/values if needed
             header("{$name}: {$value}");
        }

        // Send the body using the object's property
        echo $this->body;

        // Terminate script execution after sending the response
        exit;
    }
}