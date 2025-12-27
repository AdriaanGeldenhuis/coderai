import fs from 'fs/promises';
import path from 'path';

interface ScanResult {
  safe: boolean;
  reason?: string;
  details?: Record<string, unknown>;
}

// Simple file scanner (in production, use ClamAV or similar)
export async function scanFile(filePath: string, mimeType: string): Promise<ScanResult> {
  try {
    const stats = await fs.stat(filePath);

    // Basic checks
    const checks: ScanResult[] = [];

    // Check file size (max 100MB)
    if (stats.size > 100 * 1024 * 1024) {
      checks.push({ safe: false, reason: 'File too large (max 100MB)' });
    }

    // Check for potentially dangerous extensions
    const ext = path.extname(filePath).toLowerCase();
    const dangerousExtensions = ['.exe', '.bat', '.cmd', '.scr', '.pif', '.com', '.vbs', '.js', '.jse', '.wsf', '.wsh', '.msc'];

    if (dangerousExtensions.includes(ext)) {
      checks.push({ safe: false, reason: `Potentially dangerous file extension: ${ext}` });
    }

    // Check for executable mime types
    const dangerousMimeTypes = [
      'application/x-executable',
      'application/x-msdos-program',
      'application/x-msdownload',
    ];

    if (dangerousMimeTypes.includes(mimeType)) {
      checks.push({ safe: false, reason: `Potentially dangerous mime type: ${mimeType}` });
    }

    // Check file magic bytes for executables
    const buffer = Buffer.alloc(4);
    const fileHandle = await fs.open(filePath, 'r');
    await fileHandle.read(buffer, 0, 4, 0);
    await fileHandle.close();

    // Check for PE (Windows executable) magic bytes
    if (buffer[0] === 0x4D && buffer[1] === 0x5A) { // MZ
      checks.push({ safe: false, reason: 'Windows executable detected' });
    }

    // Check for ELF (Linux executable) magic bytes
    if (buffer[0] === 0x7F && buffer[1] === 0x45 && buffer[2] === 0x4C && buffer[3] === 0x46) { // .ELF
      checks.push({ safe: false, reason: 'Linux executable detected' });
    }

    // Return first failed check or success
    const failed = checks.find(c => !c.safe);
    if (failed) {
      return failed;
    }

    return {
      safe: true,
      details: {
        size: stats.size,
        mimeType,
        extension: ext,
        scannedAt: new Date().toISOString(),
      }
    };
  } catch (error) {
    return {
      safe: false,
      reason: `Scan error: ${(error as Error).message}`
    };
  }
}

// Extract text content from files for indexing
export async function indexFile(filePath: string, mimeType: string): Promise<string | null> {
  try {
    const ext = path.extname(filePath).toLowerCase();

    // Text-based files
    const textExtensions = ['.txt', '.md', '.markdown', '.json', '.xml', '.yaml', '.yml', '.csv', '.log', '.ini', '.cfg', '.conf'];
    const codeExtensions = ['.js', '.ts', '.jsx', '.tsx', '.py', '.java', '.c', '.cpp', '.h', '.hpp', '.go', '.rs', '.rb', '.php', '.html', '.css', '.scss', '.less', '.sql', '.sh', '.bash', '.zsh', '.ps1'];

    if (textExtensions.includes(ext) || codeExtensions.includes(ext) || mimeType.startsWith('text/')) {
      const content = await fs.readFile(filePath, 'utf-8');
      // Limit indexed content to 100KB
      return content.substring(0, 100 * 1024);
    }

    // For other file types, we'd need specialized parsers
    // In production, use libraries like pdf-parse, mammoth, etc.
    if (mimeType === 'application/pdf') {
      // Placeholder - in production, use pdf-parse
      return '[PDF content - requires pdf-parse library]';
    }

    if (mimeType.includes('word') || mimeType.includes('document')) {
      // Placeholder - in production, use mammoth
      return '[Document content - requires mammoth library]';
    }

    // Non-indexable file types
    return null;
  } catch (error) {
    console.error('Indexing error:', error);
    return null;
  }
}
