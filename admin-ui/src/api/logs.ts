/**
 * LogsApi — typed wrapper over the admin log-viewer endpoints
 * (`/api/v1/admin/logs*`). Lists the server log files and tails one.
 *
 * @since 1.7
 */
import type { ApiClient } from './client';

/** A log file as listed by `GET /api/v1/admin/logs`. */
export interface LogFile {
  name: string;
  size: number;
  modified_at: string;
}

/** Result of tailing one log file. */
export interface LogTail {
  file: string;
  lines: string[];
  /** True when the file had more lines than were returned. */
  truncated: boolean;
}

/** Result of tailing *all* log files merged into one chronological stream. */
export interface LogTailAll {
  /** The source file names that were merged. */
  files: string[];
  /** Merged lines, each prefixed with its source file name. */
  lines: string[];
  /** True when more lines existed across the files than were returned. */
  truncated: boolean;
}

/** Typed client for the admin log endpoints. @since 1.7 */
export class LogsApi {
  constructor(private readonly client: ApiClient) {}

  /** `GET /api/v1/admin/logs` → unwraps `{ files }`. */
  async list(): Promise<LogFile[]> {
    const { files } = await this.client.get<{ files: LogFile[] }>(
      '/api/v1/admin/logs',
    );
    return Array.isArray(files) ? files : [];
  }

  /** `GET /api/v1/admin/logs/tail?file=&lines=` → `{ file, lines, truncated }`. */
  async tail(file: string, lines = 200): Promise<LogTail> {
    const res = await this.client.get<Partial<LogTail>>(
      '/api/v1/admin/logs/tail',
      { file, lines: String(lines) },
    );
    return {
      file: typeof res.file === 'string' ? res.file : file,
      lines: Array.isArray(res.lines) ? res.lines : [],
      truncated: res.truncated === true,
    };
  }

  /** `GET /api/v1/admin/logs/tail-all?lines=` → merged tail across every file. */
  async tailAll(lines = 200): Promise<LogTailAll> {
    const res = await this.client.get<Partial<LogTailAll>>(
      '/api/v1/admin/logs/tail-all',
      { lines: String(lines) },
    );
    return {
      files: Array.isArray(res.files) ? res.files : [],
      lines: Array.isArray(res.lines) ? res.lines : [],
      truncated: res.truncated === true,
    };
  }
}
