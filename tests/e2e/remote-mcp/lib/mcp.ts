import type { Endpoints } from './urls.js';
import { raw, json, type RawResponse } from './http.js';

export const MODERN_VERSION = '2026-07-28';

export interface McpTarget {
  endpoints: Endpoints;
}

let nextId = 1;

/** A stateless 2026-07-28 request: the version and capabilities travel in `_meta`. */
export function modernBody(method: string, params: Record<string, unknown>): string {
  return JSON.stringify({
    jsonrpc: '2.0',
    id: nextId++,
    method,
    params: {
      ...params,
      _meta: {
        'io.modelcontextprotocol/protocolVersion': MODERN_VERSION,
        'io.modelcontextprotocol/clientCapabilities': {},
      },
    },
  });
}

/** The headers must mirror the body; `Mcp-Name` only for tools/call. */
export function modernHeaders(method: string, name?: string): Record<string, string> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json, text/event-stream',
    'MCP-Protocol-Version': MODERN_VERSION,
    'Mcp-Method': method,
  };
  if (name !== undefined) headers['Mcp-Name'] = name;
  return headers;
}

export function mcpRequest(target: McpTarget, token: string, method: string, params: Record<string, unknown>): Promise<RawResponse> {
  const name = method === 'tools/call' ? String(params.name) : undefined;
  return raw(target.endpoints.mcp, {
    method: 'POST',
    headers: { ...modernHeaders(method, name), Authorization: `Bearer ${token}` },
    body: modernBody(method, params),
  });
}

export function callTool(target: McpTarget, token: string, name: string, args: Record<string, unknown>): Promise<RawResponse> {
  return mcpRequest(target, token, 'tools/call', { name, arguments: args });
}

/** Every page of tools/list; throws on anything but a JSON-RPC result. */
export async function listTools(target: McpTarget, token: string): Promise<unknown[]> {
  const tools: unknown[] = [];
  let cursor: string | undefined;
  for (let page = 0; page < 50; page++) {
    const res = await mcpRequest(target, token, 'tools/list', cursor ? { cursor } : {});
    const body = json<{ result?: { tools?: unknown[]; nextCursor?: string } }>(res);
    if (res.status !== 200 || !body?.result || !Array.isArray(body.result.tools)) {
      throw new Error(`tools/list failed: status ${res.status}`);
    }
    tools.push(...body.result.tools);
    cursor = body.result.nextCursor;
    if (!cursor) return tools;
  }
  throw new Error('tools/list kept returning a cursor');
}
