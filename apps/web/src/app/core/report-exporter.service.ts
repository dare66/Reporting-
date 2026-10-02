import { Injectable, inject } from '@angular/core';
import { Api } from './api.service';
import { Envelope, ExportFormat, ReportExport } from './models';

/** Exports run on a worker: queue the job, poll until it settles, then download the file. */
@Injectable({ providedIn: 'root' })
export class ReportExporter {
  private api = inject(Api);

  async download(reportId: string, format: ExportFormat, fileStem: string, timeoutSeconds = 90): Promise<void> {
    const queued = (await this.api.post<Envelope<ReportExport>>(`/reports/${reportId}/exports`, { format })).data;
    let current = queued;
    for (let i = 0; i < timeoutSeconds && current.status !== 'ready' && current.status !== 'failed'; i++) {
      await new Promise((r) => setTimeout(r, 1000));
      current = (await this.api.get<Envelope<ReportExport>>(`/report-exports/${queued.id}`)).data;
    }
    if (current.status !== 'ready') {
      throw new Error(current.error ?? 'The export did not finish. Please try again.');
    }
    const slug = fileStem.replace(/[^\w]+/g, '-').toLowerCase() || 'report';
    await this.api.download(`/report-exports/${queued.id}/download`, `${slug}.${format}`);
  }
}
