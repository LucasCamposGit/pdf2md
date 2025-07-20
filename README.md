# PDF to Markdown Converter

A PHP-based web API that converts PDF files to Markdown format using OCR technology.

## Features

- Convert PDF files to Markdown format
- RESTful API endpoint
- File upload via HTTP POST
- Cross-origin resource sharing (CORS) support
- Error handling and validation
- Filename sanitization for security

## Requirements

- PHP 8.0 or higher
- Composer
- Mistral API key for OCR functionality

## Installation

1. Clone the repository:
   ```bash
   git clone <repository-url>
   cd pdf2md
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Create a `.env` file in the root directory with your configuration (copy from `.env.example` if available):
   ```
   MISTRAL_API_KEY=your_mistral_api_key_here
   ```

## Usage

### Starting the Server

Start the PHP development server:
```bash
php -S localhost:8000 -t public
```

The API will be available at `http://localhost:8000`.

### API Endpoint

**POST** `/pdf2md`

Convert a PDF file to Markdown format.

#### Request
- Method: `POST`
- Content-Type: `application/pdf` (or send raw PDF data)
- Headers:
  - `X-Filename` (optional): Original filename of the PDF

#### Response
- Success (200): Returns Markdown content
- Error (400): Bad request (no PDF data, invalid file)
- Error (500): Server error during processing

#### Example using cURL
```bash
curl -X POST http://localhost:8000/pdf2md \
  -H "X-Filename: document.pdf" \
  -H "Content-Type: application/pdf" \
  --data-binary @your-document.pdf
```

## Project Structure

```
pdf2md/
├── core/
│   ├── application/     # Application bootstrap
│   ├── controller/      # HTTP request handlers
│   ├── database/        # Database connection
│   ├── error/          # Error handling
│   ├── model/          # Business logic
│   ├── request/        # Request processing
│   ├── response/       # Response formatting
│   └── router/         # Route handling
├── public/
│   └── index.php       # Entry point
├── vendor/             # Composer dependencies
└── composer.json       # Dependencies configuration
```

## Development

The project follows PSR-4 autoloading standards and uses a simple MVC architecture.

## License

This project is licensed under the MIT License.