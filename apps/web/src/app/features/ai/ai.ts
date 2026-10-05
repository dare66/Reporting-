import { Location } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  OnDestroy,
  OnInit,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { AgentStep, AiBlock, Conversation, MetaBlock } from '../../core/ai-models';
import { AiStream } from '../../core/ai-stream';
import { Api, errorMessage } from '../../core/api.service';
import { Auth } from '../../core/auth.service';
import { ago } from '../../core/format';
import { ConversationSummary, Envelope, Evidence } from '../../core/models';
import { EvidenceSheet } from '../../shared/evidence';
import { Icon } from '../../shared/icon';
import { AiBlockView } from './ai-blocks';

interface Turn {
  role: 'user' | 'assistant';
  text: string;
  blocks: AiBlock[];
  steps: AgentStep[];
  suggestions: string[];
  evidence: Evidence[];
  planner?: string;
  narrator?: string;
  runId?: string;
  pending?: boolean;
  error?: string;
  feedback?: number;
}

const EMPTY_TURN: Turn = { role: 'user', text: '', blocks: [], steps: [], suggestions: [], evidence: [] };

const AGENTS = [
  {
    key: 'intent',
    label: 'Intent',
    x: 50,
    y: 8,
    d: 'Understands what you are asking: a question, an investigation, a report, an alert…',
  },
  {
    key: 'semantic',
    label: 'Semantic',
    x: 18,
    y: 26,
    d: 'Maps your words to governed metrics, dimensions and members.',
  },
  {
    key: 'governance',
    label: 'Governance',
    x: 82,
    y: 26,
    d: 'Checks permissions and sensitivity before any data is touched.',
  },
  {
    key: 'sql',
    label: 'SQL',
    x: 50,
    y: 40,
    d: 'Compiles plans through the permission-aware semantic compiler — never free SQL.',
  },
  { key: 'anomaly', label: 'Anomaly', x: 16, y: 58, d: 'Finds unusual behaviour with seasonal robust statistics.' },
  {
    key: 'root_cause',
    label: 'Root cause',
    x: 50,
    y: 62,
    d: 'Decomposes changes across dimensions to find the drivers.',
  },
  { key: 'forecast', label: 'Forecast', x: 84, y: 58, d: 'Projects metrics with backtested confidence intervals.' },
  { key: 'visualization', label: 'Visualise', x: 28, y: 82, d: 'Chooses the chart that answers the question.' },
  {
    key: 'narrative',
    label: 'Narrative',
    x: 72,
    y: 82,
    d: 'Writes executive language — every number grounded in evidence.',
  },
  { key: 'report', label: 'Report', x: 50, y: 96, d: 'Assembles reports, dashboards and alerts.' },
];
const EDGES = [
  ['intent', 'semantic'],
  ['intent', 'governance'],
  ['semantic', 'sql'],
  ['governance', 'sql'],
  ['sql', 'anomaly'],
  ['sql', 'root_cause'],
  ['sql', 'forecast'],
  ['anomaly', 'visualization'],
  ['root_cause', 'visualization'],
  ['root_cause', 'narrative'],
  ['forecast', 'narrative'],
  ['visualization', 'report'],
  ['narrative', 'report'],
];

function agentByKey(key: string) {
  const agent = AGENTS.find((x) => x.key === key);
  if (!agent) throw new Error(`Unknown agent ${key} in the pipeline diagram.`);
  return agent;
}

@Component({
  selector: 'app-ai',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, AiBlockView, EvidenceSheet],
  templateUrl: './ai.html',
  styleUrl: './ai.scss',
})
export class AiAnalyst implements OnInit, OnDestroy {
  private api = inject(Api);
  private stream = inject(AiStream);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private location = inject(Location);
  readonly auth = inject(Auth);
  private thread = viewChild<ElementRef<HTMLElement>>('thread');

  readonly agents = AGENTS;
  readonly edges = EDGES.map(([a, b]) => [agentByKey(a), agentByKey(b)]);
  readonly activeAgent = signal<string | null>(null);
  readonly hoverAgent = signal<(typeof AGENTS)[number] | null>(null);
  readonly conversations = signal<ConversationSummary[]>([]);
  readonly conversationId = signal<string | null>(null);
  readonly turns = signal<Turn[]>([]);
  readonly draft = signal('');
  readonly busy = signal(false);
  readonly evidence = signal<Evidence[] | null>(null);
  readonly showHistory = signal(false);
  private abort?: AbortController;
  ago = ago;

  readonly starters = [
    "Show me this month's performance",
    'Why did SLA fall?',
    'Compare China and India applications',
    'What happens if applications increase by 20%?',
    'Forecast revenue for the next 12 months',
    'Create a monthly CEO report',
  ];

  async ngOnInit() {
    this.loadConversations();
    const q = this.route.snapshot.queryParamMap.get('q');
    const c = this.route.snapshot.queryParamMap.get('conversation');
    if (c) await this.open(c);
    if (q) {
      // Clears the question from the address bar without a second navigation (which would cut the page transition short).
      this.location.replaceState(this.router.url.split('?')[0]);
      this.send(q);
    }
  }

  ngOnDestroy() {
    this.abort?.abort();
  }

  async loadConversations() {
    try {
      this.conversations.set((await this.api.get<Envelope<ConversationSummary[]>>('/ai/conversations')).data);
    } catch {
      /* sidebar optional */
    }
  }

  async open(id: string) {
    let c: Conversation;
    try {
      c = (await this.api.get<Envelope<Conversation>>(`/ai/conversations/${id}`)).data;
    } catch (e) {
      this.turns.set([{ ...EMPTY_TURN, role: 'assistant', error: errorMessage(e) }]);
      return;
    }
    this.conversationId.set(id);
    this.showHistory.set(false);
    this.turns.set(
      c.messages.map((m): Turn => {
        const blocks = m.blocks ?? [];
        const meta = blocks.find((b): b is MetaBlock => b.type === 'meta');
        return {
          role: m.role,
          text: m.content,
          blocks: blocks.filter((b): b is AiBlock => b.type !== 'meta'),
          steps: meta?.trace ?? [],
          suggestions: meta?.suggestions ?? [],
          evidence: meta?.evidence ?? [],
          planner: meta?.planner ?? undefined,
          narrator: meta?.narrator ?? undefined,
          runId: m.run_id ?? undefined,
        };
      }),
    );
    this.scroll();
  }

  newConversation() {
    this.abort?.abort();
    this.conversationId.set(null);
    this.turns.set([]);
    this.showHistory.set(false);
  }

  async send(text?: string) {
    const q = (text ?? this.draft()).trim();
    if (!q || this.busy()) return;
    this.draft.set('');
    this.busy.set(true);
    const assistant: Turn = { ...EMPTY_TURN, role: 'assistant', pending: true };
    this.turns.update((t) => [...t, { ...EMPTY_TURN, role: 'user', text: q }, assistant]);
    this.scroll();
    const patch = (fn: (t: Turn) => void) =>
      this.turns.update((ts) => {
        const copy = [...ts];
        const last = { ...copy[copy.length - 1] };
        fn(last);
        copy[copy.length - 1] = last;
        return copy;
      });
    this.abort = new AbortController();
    try {
      await this.stream.ask(
        q,
        this.conversationId(),
        {
          step: (s) => {
            if (s.agent !== 'start') {
              patch((t) => (t.steps = [...t.steps, s]));
              this.activeAgent.set(s.agent);
            }
          },
          block: (b) => {
            patch((t) => (t.blocks = [...t.blocks, b]));
            this.scroll();
          },
          answer: (a) => patch((t) => (t.text = a.text)),
          done: (r) => {
            patch((t) => {
              t.pending = false;
              t.text = r.answer;
              t.blocks = r.blocks;
              t.suggestions = r.suggestions;
              t.evidence = r.evidence;
              t.planner = r.planner ?? undefined;
              t.narrator = r.narrator ?? undefined;
              t.runId = r.run_id;
              t.steps = r.trace;
            });
            if (r.conversation_id && !this.conversationId()) {
              this.conversationId.set(r.conversation_id);
              this.loadConversations();
            }
          },
          error: (e) =>
            patch((t) => {
              t.pending = false;
              t.error = e.message;
            }),
        },
        this.abort.signal,
      );
    } catch (e) {
      if (!(e instanceof DOMException && e.name === 'AbortError'))
        patch((t) => {
          t.pending = false;
          t.error = 'The connection to the AI analyst was interrupted.';
        });
    } finally {
      this.busy.set(false);
      this.activeAgent.set(null);
      this.scroll();
    }
  }

  async feedback(t: Turn, rating: number) {
    if (!t.runId) return;
    try {
      await this.api.post(`/ai/runs/${t.runId}/feedback`, { rating });
      this.turns.update((ts) => ts.map((x) => (x === t ? { ...x, feedback: rating } : x)));
    } catch {
      // Feedback is optional; the thumbs simply stay unselected.
    }
  }

  keydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      this.send();
    }
  }

  /** Renders the safe subset of markdown the analyst uses (emphasis, line breaks). */
  html(text: string) {
    const esc = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return esc
      .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
      .replace(/(^|\s)_(.+?)_(?=\s|$)/gs, '$1<em>$2</em>')
      .replace(/\n\n/g, '<br><br>');
  }

  stepState(key: string): 'done' | 'active' | 'idle' {
    if (this.activeAgent() === key) return 'active';
    const last = this.turns().at(-1);
    return last?.steps.some((s) => s.agent === key) ? 'done' : 'idle';
  }

  private scroll() {
    setTimeout(() => {
      const el = this.thread()?.nativeElement;
      el?.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
    }, 30);
  }
}
