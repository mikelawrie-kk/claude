const express = require('express');
const archiver = require('archiver');
const cors = require('cors');
const fs = require('fs');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(cors());
app.use(express.json());
app.use(express.static('public'));

// Route to download all files as zip
app.get('/download/zip', (req, res) => {
  const archive = archiver('zip', {
    zlib: { level: 9 } // Maximum compression
  });

  // Set response headers
  res.attachment('files.zip');
  res.setHeader('Content-Type', 'application/zip');

  // Pipe archive data to the response
  archive.pipe(res);

  // Add files from the 'files' directory
  const filesDir = path.join(__dirname, 'files');

  if (fs.existsSync(filesDir)) {
    // Add all files from the files directory
    archive.directory(filesDir, false);
  } else {
    console.log('Files directory does not exist');
  }

  // Handle warnings and errors
  archive.on('warning', (err) => {
    if (err.code === 'ENOENT') {
      console.warn('Warning:', err);
    } else {
      throw err;
    }
  });

  archive.on('error', (err) => {
    console.error('Archive error:', err);
    res.status(500).send({ error: err.message });
  });

  // Finalize the archive
  archive.finalize();
});

// Route to download specific files as zip
app.post('/download/zip/custom', (req, res) => {
  const { files: fileList } = req.body;

  if (!fileList || !Array.isArray(fileList)) {
    return res.status(400).send({ error: 'Invalid file list' });
  }

  const archive = archiver('zip', {
    zlib: { level: 9 }
  });

  res.attachment('custom-files.zip');
  res.setHeader('Content-Type', 'application/zip');
  archive.pipe(res);

  const filesDir = path.join(__dirname, 'files');

  fileList.forEach((fileName) => {
    const filePath = path.join(filesDir, fileName);
    if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
      archive.file(filePath, { name: fileName });
    }
  });

  archive.on('error', (err) => {
    console.error('Archive error:', err);
    res.status(500).send({ error: err.message });
  });

  archive.finalize();
});

// Route to list available files
app.get('/files', (req, res) => {
  const filesDir = path.join(__dirname, 'files');

  if (!fs.existsSync(filesDir)) {
    return res.json([]);
  }

  const files = fs.readdirSync(filesDir).filter((file) => {
    return fs.statSync(path.join(filesDir, file)).isFile();
  });

  res.json(files);
});

// Start server
app.listen(PORT, () => {
  console.log(`Server is running on http://localhost:${PORT}`);
  console.log(`Download all files: http://localhost:${PORT}/download/zip`);
});
