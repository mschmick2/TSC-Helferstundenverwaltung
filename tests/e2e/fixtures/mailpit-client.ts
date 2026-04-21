/**
 * TS-Port von tests/Support/MailPitClient.php.
 * Nutzt die in Node 18+ global verfuegbare fetch-API.
 */
export interface MailpitMessage {
  ID: string;
  Subject: string;
  From?: { Address: string; Name: string };
  To?: Array<{ Address: string; Name: string }>;
  Snippet?: string;
  Created?: string;
}

export interface MailpitMessageDetail extends MailpitMessage {
  HTML?: string;
  Text?: string;
}

export class MailpitClient {
  constructor(private readonly apiUrl = 'http://127.0.0.1:8025') {}

  async isAvailable(): Promise<boolean> {
    try {
      const res = await fetch(`${this.apiUrl}/api/v1/info`);
      return res.ok;
    } catch {
      return false;
    }
  }

  async getMessages(limit = 50): Promise<MailpitMessage[]> {
    const res = await fetch(`${this.apiUrl}/api/v1/messages?limit=${limit}`);
    if (!res.ok) return [];
    const data = (await res.json()) as { messages?: MailpitMessage[] };
    return data.messages ?? [];
  }

  async getMessage(id: string): Promise<MailpitMessageDetail | null> {
    const res = await fetch(`${this.apiUrl}/api/v1/message/${encodeURIComponent(id)}`);
    if (!res.ok) return null;
    return (await res.json()) as MailpitMessageDetail;
  }

  async deleteAll(): Promise<void> {
    await fetch(`${this.apiUrl}/api/v1/messages`, { method: 'DELETE' });
  }

  /**
   * Wartet auf eine Nachricht, die to/subject matcht. Polling bis timeoutMs.
   */
  async waitForMessage(
    criteria: { to?: string; subject?: string; timeoutMs?: number; pollMs?: number }
  ): Promise<MailpitMessageDetail | null> {
    const timeout = criteria.timeoutMs ?? 10_000;
    const poll = criteria.pollMs ?? 500;
    const deadline = Date.now() + timeout;

    while (Date.now() < deadline) {
      const messages = await this.getMessages();
      for (const msg of messages) {
        if (criteria.to) {
          const match = (msg.To ?? []).some(
            (r) => r.Address.toLowerCase() === criteria.to!.toLowerCase()
          );
          if (!match) continue;
        }
        if (criteria.subject) {
          if (!msg.Subject.toLowerCase().includes(criteria.subject.toLowerCase())) continue;
        }
        return await this.getMessage(msg.ID);
      }
      await new Promise((r) => setTimeout(r, poll));
    }
    return null;
  }
}
