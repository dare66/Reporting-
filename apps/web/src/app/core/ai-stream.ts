import { Injectable, inject } from '@angular/core';
import { AI } from './api.service';
import { AgentStep, AiBlock, ChatResult } from './ai-models';
import { Auth } from './auth.service';
import { JsonObject } from './models';

/** Payload of each server-sent event, by event name. */
export interface StreamEvents {
  step: AgentStep;
  plan: JsonObject;
  block: AiBlock;
  answer: { text: string };
  done: ChatResult;
  error: { message: string };
}

export type StreamHandlers = { [E in keyof StreamEvents]?: (payload: StreamEvents[E]) => void };

const EVENTS = new Set<string>(['step', 'plan', 'block', 'answer', 'done', 'error']);

/** POST + server-sent events (EventSource cannot POST), parsed incrementally. */
@Injectable({ providedIn: 'root' })
export class AiStream {
  private auth = inject(Auth);

  async ask(question: string, conversationId: string | null, h: StreamHandlers, signal?: AbortSignal): Promise<void> {
    const call = () =>
      fetch(`${AI}/chat/stream`, {
        method: 'POST',
        signal,
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${this.auth.accessToken()}` },
        body: JSON.stringify({ question, conversation_id: conversationId }),
      });
    let res = await call();
    if (res.status === 401 && (await this.auth.refresh())) res = await call();
    if (!res.ok || !res.body) {
      let msg = 'The AI analyst is unavailable right now.';
      try {
        msg = ((await res.json()) as { detail?: string }).detail ?? msg;
      } catch {
        /* keep default */
      }
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
        if (!event || !data || !EVENTS.has(event)) continue;
        this.dispatch(h, event as keyof StreamEvents, JSON.parse(data));
      }
    }
  }

  /** The service owns the payload shapes; each event is handed to its typed handler. */
  private dispatch<E extends keyof StreamEvents>(h: StreamHandlers, event: E, payload: unknown) {
    const handler = h[event] as ((p: StreamEvents[E]) => void) | undefined;
    handler?.(payload as StreamEvents[E]);
  }
}
