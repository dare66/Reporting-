export type Mode = "simulation" | "live" | "review";

export interface User { id: number; username: string; full_name: string; roles: string[]; is_active: boolean; last_login_at?: string | null; }

export interface Assessment {
  summary?: string; confirmed?: string[]; possible_causes?: string[]; recommendations?: string[];
  severity?: "info" | "warning" | "error"; source?: string;
}

export interface Job {
  reference: string; type: string; target: string; port?: number | null; status: string; outcome: string;
  mode: Mode; simulated: boolean; source_interface: string; source_network: string; user?: string | null;
  started_at: string; duration_ms?: number | null; parameters: Record<string, any>;
  result: Record<string, any>; assessment: Assessment; raw_output: string; error?: string;
}

export interface Device {
  id: number; ip_address: string; hostname?: string | null; mac_address?: string | null; vendor?: string | null;
  os_guess?: string | null; responded_icmp: boolean; responded_tcp: boolean; responded_arp: boolean;
  detection_methods: string[]; latency_ms?: number | null; ports: { port: number; state: string; response_ms?: number | null }[];
}

export interface Scan {
  reference: string; range_input: string; normalized_range: string; address_count: number; methods: string[];
  ports: number[]; status: string; progress: number; responding: number; mode: Mode; simulated: boolean;
  source_network: string; user?: string | null; created_at: string; started_at?: string | null; finished_at?: string | null;
  error?: string; notes: string[]; devices?: Device[];
}

export interface Server {
  id: number; server_code: string; name: string; hostname: string; ip_address: string; environment: string;
  operating_system: string; application?: string | null; category: string; technical_owner: string; business_owner: string;
  criticality: string; approved_ports: number[]; description: string; registered_at?: string | null;
  last_discovered_at?: string | null; status: string; is_sample: boolean;
}
