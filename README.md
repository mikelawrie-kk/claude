# File Zip Download

A simple Node.js web application that allows you to download files as a zip archive. This application provides both a web interface and API endpoints for downloading all files or selecting specific files to download.

## Features

- Download all files with a single click
- Select specific files for custom downloads
- Maximum compression for smaller file sizes
- Support for multiple file types
- Clean and intuitive web interface
- RESTful API endpoints

## Prerequisites

- Node.js (v14 or higher)
- npm (Node Package Manager)

## Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd claude
```

2. Install dependencies:
```bash
npm install
```

## Usage

1. Start the server:
```bash
npm start
```

For development with auto-reload:
```bash
npm run dev
```

2. Open your browser and navigate to:
```
http://localhost:3000
```

3. Add files to the `files` directory that you want to make available for download.

## API Endpoints

### Download All Files
- **Endpoint**: `GET /download/zip`
- **Description**: Downloads all files from the `files` directory as a zip archive
- **Example**:
```bash
curl -O http://localhost:3000/download/zip
```

### Download Selected Files
- **Endpoint**: `POST /download/zip/custom`
- **Description**: Downloads specific files as a zip archive
- **Request Body**:
```json
{
  "files": ["example1.txt", "data.json"]
}
```
- **Example**:
```bash
curl -X POST http://localhost:3000/download/zip/custom \
  -H "Content-Type: application/json" \
  -d '{"files": ["example1.txt", "data.json"]}' \
  -O
```

### List Available Files
- **Endpoint**: `GET /files`
- **Description**: Returns a list of all available files
- **Example**:
```bash
curl http://localhost:3000/files
```

## Project Structure

```
claude/
├── files/              # Directory containing files to download
│   ├── example1.txt
│   ├── example2.txt
│   └── data.json
├── public/            # Static files served to the client
│   └── index.html    # Web interface
├── server.js         # Express server with zip functionality
├── package.json      # Node.js dependencies and scripts
└── README.md         # This file
```

## How It Works

1. The Express server serves a web interface from the `public` directory
2. Files placed in the `files` directory are made available for download
3. The `archiver` library creates zip archives on-the-fly
4. Users can download all files or select specific files via the web interface or API

## Dependencies

- **express**: Web framework for Node.js
- **archiver**: Library for creating zip archives
- **cors**: Enable CORS for API requests

## Configuration

You can change the server port by setting the `PORT` environment variable:

```bash
PORT=8080 npm start
```

## Adding Your Own Files

Simply place any files you want to make available for download in the `files` directory. The application will automatically include them in the zip downloads.

## License

MIT
