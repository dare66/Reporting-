import { Injectable, inject } from '@angular/core';
import { AI } from './api.service';
import { Auth } from './auth.service';

export interface StreamHandlers {
  step?: (s: any) => void; plan?: (p: any) => void; block?: (b: any) => void; answer?: (a: { text: string }) => void;
  done?: (r: any) => void; error?: (e: { message: string }) => void;
}

/** POST + server-sent events (EventSource cannot POST), parsed incrementally. */
@Injectable({ providedIn: 'root' })
export class AiStream {
  private auth = inject(Auth);

  async ask(question: string, conversationId: string | null, h: StreamHandlers, signal?: AbortSignal): Promise<void> {
    const call = () => fetch(`${AI}/chat/stream`, {
      method: 'POST', signal,
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${this.auth.accessToken()}` },
      body: JSON.stringify({ question, conversation_id: conversationId }),
    });
    let res = await call();
    if (res.status === 401 && (await this.auth.refresh())) res = await call();
    if (!res.ok || !res.body) {
      let msg = 'The AI analyst is unavailable right now.';
      try { msg = (await res.json()).detail ?? msg; } catch { /* keep default */ }
      h.error?.({ message: msg });
      return;
    }
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let idx: number;
      while ((idx = buffer.indexOf('\n\n')) >= 0) {
        const raw = buffer.slice(0, idx);
        buffer = buffer.slice(idx + 2);
        const event = /^event: (.*)$/m.exec(raw)?.[1];
        const data = /^data: (.*)$/m.exec(raw)?.[1];
        if (!event || !data) continue;
        const payload = JSON.parse(data);
        (h as any)[event]?.(payload);
      }
    }
  }
}
