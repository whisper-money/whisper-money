import { Client } from '@modelcontextprotocol/sdk/client';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import { Type } from '@sinclair/typebox';
import { existsSync } from 'node:fs';
import { readFile, writeFile } from 'node:fs/promises';
import { homedir, tmpdir } from 'node:os';
import path from 'node:path';

type JsonObject = Record<string, unknown>;

type PiToolInfo = {
    name: string;
};

type PiUiContext = {
    notify(message: string, level: 'info' | 'warning' | 'error'): void;
};

type PiReloadContext = {
    hasUI: boolean;
    ui: PiUiContext;
    reload(): Promise<void>;
};

type PiSessionContext = {
    cwd: string;
    hasUI: boolean;
    ui: PiUiContext;
};

type PiToolRegistration = {
    name: string;
    label: string;
    description: string;
    promptSnippet: string;
    parameters: unknown;
    execute(
        toolCallId: string,
        params: JsonObject,
        signal: AbortSignal | undefined,
        onUpdate: ((update: JsonObject) => void) | undefined,
    ): Promise<{
        content: Array<{ type: 'text'; text: string }>;
        details: JsonObject;
    }>;
};

type PiExtensionApi = {
    getAllTools(): PiToolInfo[];
    registerTool(tool: PiToolRegistration): void;
    registerCommand(
        name: string,
        command: {
            description: string;
            handler(args: string, ctx: PiReloadContext): Promise<void>;
        },
    ): void;
    on(
        event: 'session_start',
        handler: (event: JsonObject, ctx: PiSessionContext) => Promise<void>,
    ): void;
    on(event: 'session_shutdown', handler: () => Promise<void>): void;
};

type McpTool = {
    name: string;
    title?: string;
    description?: string;
    inputSchema?: JsonObject;
};

type StdioServerConfig = {
    kind: 'stdio';
    name: string;
    command: string;
    args: string[];
    env?: Record<string, string>;
    cwd?: string;
};

type HttpServerConfig = {
    kind: 'http';
    name: string;
    url: string;
    headers?: Record<string, string>;
};

type ResolvedServerConfig = StdioServerConfig | HttpServerConfig;

type ServerState = {
    config: ResolvedServerConfig;
    client?: Client;
    transport?: StdioClientTransport | StreamableHTTPClientTransport;
    tools: McpTool[];
    error?: string;
};

type RegisteredToolState = {
    serverName: string;
    sourceToolName: string;
    registeredToolName: string;
    description?: string;
};

const DEFAULT_MAX_BYTES = 50 * 1024;
const DEFAULT_MAX_LINES = 2000;
const OPENCODE_MCP_AUTH_PATH = path.join(
    homedir(),
    '.local',
    'share',
    'opencode',
    'mcp-auth.json',
);

function safeToolName(value: string): string {
    return (
        value
            .replace(/[^a-zA-Z0-9_-]/g, '_')
            .replace(/_+/g, '_')
            .replace(/^_+|_+$/g, '') || 'tool'
    );
}

function safeServerSlug(value: string): string {
    return safeToolName(value).toLowerCase();
}

function titleCase(value: string): string {
    return value
        .split(/[-_\s]+/)
        .filter(Boolean)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function joinDescription(
    ...parts: Array<string | undefined>
): string | undefined {
    const text = parts
        .filter(
            (part): part is string =>
                typeof part === 'string' && part.trim().length > 0,
        )
        .join(' ')
        .trim();
    return text.length > 0 ? text : undefined;
}

function schemaDescription(schema: JsonObject): string | undefined {
    const enumValues = Array.isArray(schema.enum)
        ? schema.enum.filter((item) =>
              ['string', 'number', 'boolean'].includes(typeof item),
          )
        : [];
    const enumDescription =
        enumValues.length > 0
            ? `Allowed values: ${enumValues.join(', ')}.`
            : undefined;

    return joinDescription(
        typeof schema.title === 'string' ? schema.title : undefined,
        typeof schema.description === 'string' ? schema.description : undefined,
        enumDescription,
    );
}

function toTypeBoxSchema(schema: unknown): unknown {
    if (!schema || typeof schema !== 'object' || Array.isArray(schema)) {
        return Type.Any();
    }

    const jsonSchema = schema as JsonObject;
    const description = schemaDescription(jsonSchema);

    if (jsonSchema.const !== undefined) {
        if (typeof jsonSchema.const === 'string') {
            return Type.Literal(
                jsonSchema.const,
                description ? { description } : {},
            );
        }

        if (typeof jsonSchema.const === 'number') {
            return Type.Literal(
                jsonSchema.const,
                description ? { description } : {},
            );
        }

        if (typeof jsonSchema.const === 'boolean') {
            return Type.Literal(
                jsonSchema.const,
                description ? { description } : {},
            );
        }
    }

    if (Array.isArray(jsonSchema.type)) {
        const nonNullTypes = jsonSchema.type.filter(
            (item): item is string =>
                typeof item === 'string' && item !== 'null',
        );
        if (nonNullTypes.length > 0) {
            return toTypeBoxSchema({ ...jsonSchema, type: nonNullTypes[0] });
        }
    }

    if (Array.isArray(jsonSchema.oneOf) && jsonSchema.oneOf.length > 0) {
        return toTypeBoxSchema(jsonSchema.oneOf[0]);
    }

    if (Array.isArray(jsonSchema.anyOf) && jsonSchema.anyOf.length > 0) {
        return toTypeBoxSchema(jsonSchema.anyOf[0]);
    }

    if (jsonSchema.type === 'object' || jsonSchema.properties) {
        const rawProperties = jsonSchema.properties;
        const required = new Set(
            Array.isArray(jsonSchema.required)
                ? jsonSchema.required.filter(
                      (item): item is string => typeof item === 'string',
                  )
                : [],
        );
        const properties: Record<string, unknown> = {};

        if (
            rawProperties &&
            typeof rawProperties === 'object' &&
            !Array.isArray(rawProperties)
        ) {
            for (const [key, value] of Object.entries(rawProperties)) {
                const propertySchema = toTypeBoxSchema(value);
                properties[key] = required.has(key)
                    ? propertySchema
                    : Type.Optional(propertySchema as never);
            }
        }

        return Type.Object(properties, {
            additionalProperties: jsonSchema.additionalProperties === true,
            ...(description ? { description } : {}),
        });
    }

    if (jsonSchema.type === 'array') {
        return Type.Array(
            toTypeBoxSchema(jsonSchema.items),
            description ? { description } : {},
        );
    }

    if (jsonSchema.type === 'string') {
        return Type.String(description ? { description } : {});
    }

    if (jsonSchema.type === 'integer') {
        return Type.Integer(description ? { description } : {});
    }

    if (jsonSchema.type === 'number') {
        return Type.Number(description ? { description } : {});
    }

    if (jsonSchema.type === 'boolean') {
        return Type.Boolean(description ? { description } : {});
    }

    return Type.Any(description ? { description } : {});
}

async function readJsonFile(filePath: string): Promise<JsonObject | undefined> {
    if (!existsSync(filePath)) {
        return undefined;
    }

    const content = await readFile(filePath, 'utf8');
    return JSON.parse(content) as JsonObject;
}

async function findUp(
    startDir: string,
    fileName: string,
): Promise<string | undefined> {
    let currentDir = path.resolve(startDir);

    while (true) {
        const candidate = path.join(currentDir, fileName);
        if (existsSync(candidate)) {
            return candidate;
        }

        const parentDir = path.dirname(currentDir);
        if (parentDir === currentDir) {
            return undefined;
        }

        currentDir = parentDir;
    }
}

function normalizeStringRecord(
    value: unknown,
): Record<string, string> | undefined {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return undefined;
    }

    const output: Record<string, string> = {};

    for (const [key, item] of Object.entries(value)) {
        if (typeof item === 'string') {
            output[key] = item;
        }
    }

    return Object.keys(output).length > 0 ? output : undefined;
}

function isEnabled(server: JsonObject): boolean {
    if (typeof server.enabled === 'boolean') {
        return server.enabled;
    }

    return true;
}

function normalizeMcpServers(
    raw: JsonObject,
    cwd: string,
): ResolvedServerConfig[] {
    const servers = raw.mcpServers;
    if (!servers || typeof servers !== 'object' || Array.isArray(servers)) {
        return [];
    }

    const output: ResolvedServerConfig[] = [];

    for (const [name, value] of Object.entries(servers)) {
        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            continue;
        }

        const server = value as JsonObject;
        if (!isEnabled(server)) {
            continue;
        }

        if (typeof server.command === 'string') {
            output.push({
                kind: 'stdio',
                name,
                command: server.command,
                args: Array.isArray(server.args)
                    ? server.args.filter(
                          (item): item is string => typeof item === 'string',
                      )
                    : [],
                env: normalizeStringRecord(server.env),
                cwd,
            });
            continue;
        }

        if (typeof server.url === 'string') {
            output.push({
                kind: 'http',
                name,
                url: server.url,
                headers: normalizeStringRecord(server.headers),
            });
        }
    }

    return output;
}

function normalizeOpencodeServers(
    raw: JsonObject,
    cwd: string,
): ResolvedServerConfig[] {
    const servers = raw.mcp;
    if (!servers || typeof servers !== 'object' || Array.isArray(servers)) {
        return [];
    }

    const output: ResolvedServerConfig[] = [];

    for (const [name, value] of Object.entries(servers)) {
        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            continue;
        }

        const server = value as JsonObject;
        if (!isEnabled(server)) {
            continue;
        }

        if (
            server.type === 'local' &&
            Array.isArray(server.command) &&
            server.command.length > 0
        ) {
            const commandParts = server.command.filter(
                (item): item is string => typeof item === 'string',
            );
            if (commandParts.length === 0) {
                continue;
            }

            output.push({
                kind: 'stdio',
                name,
                command: commandParts[0],
                args: commandParts.slice(1),
                env: normalizeStringRecord(server.env),
                cwd,
            });
            continue;
        }

        if (
            (server.type === 'remote' || server.type === 'http') &&
            typeof server.url === 'string'
        ) {
            output.push({
                kind: 'http',
                name,
                url: server.url,
                headers: normalizeStringRecord(server.headers),
            });
        }
    }

    return output;
}

async function discoverMcpServers(
    startDir: string,
): Promise<{ configPath?: string; servers: ResolvedServerConfig[] }> {
    const mcpConfigPath = await findUp(startDir, '.mcp.json');
    if (mcpConfigPath) {
        const raw = await readJsonFile(mcpConfigPath);
        if (raw) {
            return {
                configPath: mcpConfigPath,
                servers: normalizeMcpServers(raw, path.dirname(mcpConfigPath)),
            };
        }
    }

    const opencodeConfigPath = await findUp(startDir, 'opencode.json');
    if (opencodeConfigPath) {
        const raw = await readJsonFile(opencodeConfigPath);
        if (raw) {
            return {
                configPath: opencodeConfigPath,
                servers: normalizeOpencodeServers(
                    raw,
                    path.dirname(opencodeConfigPath),
                ),
            };
        }
    }

    return { servers: [] };
}

async function readOpencodeMcpAuth(): Promise<JsonObject | undefined> {
    try {
        return await readJsonFile(OPENCODE_MCP_AUTH_PATH);
    } catch {
        return undefined;
    }
}

function extractOpencodeAccessToken(
    auth: JsonObject | undefined,
    serverName: string,
    serverUrl: string,
): string | undefined {
    if (!auth) {
        return undefined;
    }

    const directMatch = auth[serverName];
    if (
        directMatch &&
        typeof directMatch === 'object' &&
        !Array.isArray(directMatch)
    ) {
        const directConfig = directMatch as JsonObject;
        const tokens = directConfig.tokens;
        if (
            typeof directConfig.serverUrl === 'string' &&
            directConfig.serverUrl === serverUrl &&
            tokens &&
            typeof tokens === 'object' &&
            !Array.isArray(tokens)
        ) {
            const accessToken = (tokens as JsonObject).accessToken;
            if (typeof accessToken === 'string' && accessToken.length > 0) {
                return accessToken;
            }
        }
    }

    for (const value of Object.values(auth)) {
        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            continue;
        }

        const candidate = value as JsonObject;
        if (candidate.serverUrl !== serverUrl) {
            continue;
        }

        const tokens = candidate.tokens;
        if (!tokens || typeof tokens !== 'object' || Array.isArray(tokens)) {
            continue;
        }

        const accessToken = (tokens as JsonObject).accessToken;
        if (typeof accessToken === 'string' && accessToken.length > 0) {
            return accessToken;
        }
    }

    return undefined;
}

function resolveAccessTokenFromEnv(serverName: string): string | undefined {
    const upperSnake = safeToolName(serverName)
        .replace(/-/g, '_')
        .toUpperCase();
    const candidates = [
        `MCP_${upperSnake}_ACCESS_TOKEN`,
        `MCP_${upperSnake}_TOKEN`,
        `${upperSnake}_MCP_ACCESS_TOKEN`,
        `${upperSnake}_ACCESS_TOKEN`,
    ];

    if (upperSnake === 'SENTRY') {
        candidates.push('SENTRY_MCP_ACCESS_TOKEN', 'SENTRY_ACCESS_TOKEN');
    }

    for (const key of candidates) {
        const value = process.env[key];
        if (typeof value === 'string' && value.length > 0) {
            return value;
        }
    }

    return undefined;
}

async function buildHttpHeaders(
    config: HttpServerConfig,
): Promise<Record<string, string> | undefined> {
    const headers: Record<string, string> = { ...(config.headers ?? {}) };
    const hasAuthorizationHeader = Object.keys(headers).some(
        (key) => key.toLowerCase() === 'authorization',
    );

    if (!hasAuthorizationHeader) {
        const envToken = resolveAccessTokenFromEnv(config.name);
        const opencodeAuth = envToken ? undefined : await readOpencodeMcpAuth();
        const token =
            envToken ??
            extractOpencodeAccessToken(opencodeAuth, config.name, config.url);

        if (token) {
            headers.Authorization = `Bearer ${token}`;
        }
    }

    return Object.keys(headers).length > 0 ? headers : undefined;
}

async function createServerState(
    config: ResolvedServerConfig,
): Promise<ServerState> {
    const client = new Client(
        { name: 'pi-mcp-bridge', version: '0.1.0' },
        { capabilities: {} },
    );

    if (config.kind === 'stdio') {
        const transport = new StdioClientTransport({
            command: config.command,
            args: config.args,
            env: config.env,
            cwd: config.cwd,
            stderr: 'inherit',
        });

        await client.connect(transport);
        const toolsResponse = await client.listTools();

        return {
            config,
            client,
            transport,
            tools: toolsResponse.tools,
        };
    }

    const headers = await buildHttpHeaders(config);
    const transport = new StreamableHTTPClientTransport(new URL(config.url), {
        requestInit: headers ? { headers } : undefined,
    });

    await client.connect(transport);
    const toolsResponse = await client.listTools();

    return {
        config,
        client,
        transport,
        tools: toolsResponse.tools,
    };
}

async function closeServerState(server: ServerState): Promise<void> {
    try {
        await server.transport?.close();
    } catch {
        // Ignore close failures.
    }
}

function renderContentBlock(block: JsonObject): string {
    const type = typeof block.type === 'string' ? block.type : 'unknown';

    if (type === 'text' && typeof block.text === 'string') {
        return block.text;
    }

    if (type === 'image') {
        const mimeType =
            typeof block.mimeType === 'string'
                ? block.mimeType
                : 'application/octet-stream';
        return `[image omitted: ${mimeType}]`;
    }

    if (type === 'audio') {
        const mimeType =
            typeof block.mimeType === 'string'
                ? block.mimeType
                : 'application/octet-stream';
        return `[audio omitted: ${mimeType}]`;
    }

    if (
        type === 'resource' &&
        block.resource &&
        typeof block.resource === 'object' &&
        !Array.isArray(block.resource)
    ) {
        const resource = block.resource as JsonObject;
        if (typeof resource.text === 'string') {
            return resource.text;
        }

        const uri =
            typeof resource.uri === 'string'
                ? resource.uri
                : 'unknown-resource';
        return `[resource omitted: ${uri}]`;
    }

    if (type === 'resource_link') {
        const name = typeof block.name === 'string' ? block.name : 'resource';
        const uri =
            typeof block.uri === 'string' ? block.uri : 'unknown-resource';
        return `[resource link: ${name} -> ${uri}]`;
    }

    return `[unsupported MCP content block: ${type}]`;
}

function renderStructuredContent(value: unknown): string | undefined {
    if (value === undefined) {
        return undefined;
    }

    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return undefined;
    }
}

function formatSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes}B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1).replace(/\.0$/, '')}KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1).replace(/\.0$/, '')}MB`;
}

function truncateText(content: string): {
    content: string;
    truncated: boolean;
    totalBytes: number;
    totalLines: number;
    outputBytes: number;
    outputLines: number;
} {
    const lines = content.split('\n');
    const totalLines = lines.length;
    const totalBytes = Buffer.byteLength(content, 'utf8');

    let outputLines = totalLines;
    let selectedLines = lines;

    if (selectedLines.length > DEFAULT_MAX_LINES) {
        selectedLines = selectedLines.slice(0, DEFAULT_MAX_LINES);
        outputLines = selectedLines.length;
    }

    let output = selectedLines.join('\n');
    let outputBytes = Buffer.byteLength(output, 'utf8');

    if (outputBytes > DEFAULT_MAX_BYTES) {
        let currentBytes = 0;
        const trimmedLines: string[] = [];

        for (const line of selectedLines) {
            const candidate = trimmedLines.length === 0 ? line : `\n${line}`;
            const candidateBytes = Buffer.byteLength(candidate, 'utf8');
            if (currentBytes + candidateBytes > DEFAULT_MAX_BYTES) {
                break;
            }

            trimmedLines.push(line);
            currentBytes += candidateBytes;
        }

        selectedLines = trimmedLines;
        outputLines = selectedLines.length;
        output = selectedLines.join('\n');
        outputBytes = Buffer.byteLength(output, 'utf8');
    }

    return {
        content: output,
        truncated: outputLines < totalLines || outputBytes < totalBytes,
        totalBytes,
        totalLines,
        outputBytes,
        outputLines,
    };
}

async function writeTruncatedOutput(
    content: string,
    serverName: string,
    toolName: string,
): Promise<string> {
    const filePath = path.join(
        tmpdir(),
        `pi-mcp-${safeServerSlug(serverName)}-${safeToolName(toolName)}-${Date.now()}.log`,
    );
    await writeFile(filePath, content, 'utf8');
    return filePath;
}

function summarizeProgress(progress: JsonObject): string {
    const message =
        typeof progress.message === 'string' ? progress.message : undefined;
    const amount =
        typeof progress.progress === 'number' ? progress.progress : undefined;
    const total =
        typeof progress.total === 'number' ? progress.total : undefined;

    if (message && amount !== undefined && total !== undefined) {
        return `${message} (${amount}/${total})`;
    }

    if (message && amount !== undefined) {
        return `${message} (${amount})`;
    }

    if (message) {
        return message;
    }

    if (amount !== undefined && total !== undefined) {
        return `Progress: ${amount}/${total}`;
    }

    if (amount !== undefined) {
        return `Progress: ${amount}`;
    }

    return 'Working...';
}

async function formatToolResult(
    result: JsonObject,
    serverName: string,
    toolName: string,
): Promise<{ text: string; details: JsonObject }> {
    const rawContent = Array.isArray(result.content) ? result.content : [];
    const renderedBlocks = rawContent
        .filter(
            (item): item is JsonObject =>
                Boolean(item) &&
                typeof item === 'object' &&
                !Array.isArray(item),
        )
        .map((item) => renderContentBlock(item));

    const structuredContent = renderStructuredContent(result.structuredContent);
    const sections = renderedBlocks.filter(Boolean);

    if (
        structuredContent &&
        (sections.length === 0 || !sections.includes(structuredContent))
    ) {
        sections.push(structuredContent);
    }

    const fullText = sections.join('\n\n').trim() || `${toolName} completed.`;
    const truncation = truncateText(fullText);
    let text = truncation.content;
    let fullOutputPath: string | undefined;

    if (truncation.truncated) {
        fullOutputPath = await writeTruncatedOutput(
            fullText,
            serverName,
            toolName,
        );
        text += `\n\n[Output truncated: ${truncation.outputLines} of ${truncation.totalLines} lines (${formatSize(truncation.outputBytes)} of ${formatSize(truncation.totalBytes)}). Full output saved to: ${fullOutputPath}]`;
    }

    return {
        text,
        details: {
            server: serverName,
            tool: toolName,
            fullOutputPath,
            isError: result.isError === true,
            structuredContent: result.structuredContent,
        },
    };
}

export default function mcpBridgeExtension(pi: PiExtensionApi): void {
    const serverStates = new Map<string, ServerState>();
    const toolStates = new Map<string, RegisteredToolState>();
    const registrationState = {
        configPath: undefined as string | undefined,
        loadErrors: [] as string[],
    };

    async function cleanupServers(): Promise<void> {
        await Promise.all(
            Array.from(serverStates.values()).map((server) =>
                closeServerState(server),
            ),
        );
        serverStates.clear();
    }

    async function loadServers(cwd: string): Promise<void> {
        registrationState.loadErrors = [];
        registrationState.configPath = undefined;

        await cleanupServers();

        const discovery = await discoverMcpServers(cwd);
        registrationState.configPath = discovery.configPath;

        for (const config of discovery.servers) {
            try {
                const serverState = await createServerState(config);
                serverStates.set(config.name, serverState);
            } catch (error) {
                const message =
                    error instanceof Error ? error.message : String(error);
                registrationState.loadErrors.push(`${config.name}: ${message}`);
                serverStates.set(config.name, {
                    config,
                    tools: [],
                    error: message,
                });
            }
        }
    }

    async function ensureConnected(serverName: string): Promise<ServerState> {
        const existing = serverStates.get(serverName);
        if (!existing) {
            throw new Error(`Unknown MCP server: ${serverName}`);
        }

        if (existing.client && existing.transport) {
            return existing;
        }

        const refreshed = await createServerState(existing.config);
        serverStates.set(serverName, refreshed);
        return refreshed;
    }

    function buildRegisteredToolName(
        tool: RegisteredToolState,
        nameCounts: Map<string, number>,
        reservedNames: Set<string>,
    ): string {
        const requested = safeToolName(tool.sourceToolName);
        if (
            requested === tool.sourceToolName &&
            !reservedNames.has(requested) &&
            (nameCounts.get(tool.sourceToolName) ?? 0) === 1
        ) {
            reservedNames.add(requested);
            return requested;
        }

        const base = `mcp_${safeServerSlug(tool.serverName)}_${requested}`;
        let candidate = base;
        let suffix = 2;

        while (reservedNames.has(candidate)) {
            candidate = `${base}_${suffix}`;
            suffix += 1;
        }

        reservedNames.add(candidate);
        return candidate;
    }

    function registerTools(): void {
        const allKnownTools = Array.from(serverStates.values()).flatMap(
            (server) =>
                server.tools.map((tool) => ({
                    serverName: server.config.name,
                    sourceToolName: tool.name,
                    description: tool.description,
                    title: tool.title,
                    inputSchema: tool.inputSchema,
                })),
        );

        const nameCounts = new Map<string, number>();
        for (const tool of allKnownTools) {
            nameCounts.set(
                tool.sourceToolName,
                (nameCounts.get(tool.sourceToolName) ?? 0) + 1,
            );
        }

        const reservedNames = new Set(
            pi.getAllTools().map((tool) => tool.name),
        );

        for (const tool of allKnownTools) {
            const state: RegisteredToolState = {
                serverName: tool.serverName,
                sourceToolName: tool.sourceToolName,
                registeredToolName: '',
                description: tool.description,
            };

            state.registeredToolName = buildRegisteredToolName(
                state,
                nameCounts,
                reservedNames,
            );
            toolStates.set(state.registeredToolName, state);

            const labelTitle = tool.title ?? titleCase(tool.sourceToolName);
            const description = tool.description
                ? `${tool.description} (MCP server: ${tool.serverName})`
                : `Call MCP tool ${tool.sourceToolName} on server ${tool.serverName}.`;

            pi.registerTool({
                name: state.registeredToolName,
                label: `${labelTitle}`,
                description,
                promptSnippet: `${labelTitle} via MCP server ${tool.serverName}`,
                parameters: toTypeBoxSchema(
                    tool.inputSchema ?? { type: 'object', properties: {} },
                ),
                async execute(
                    _toolCallId: string,
                    params: JsonObject,
                    signal: AbortSignal | undefined,
                    onUpdate: ((update: JsonObject) => void) | undefined,
                ) {
                    const server = await ensureConnected(state.serverName);

                    onUpdate?.({
                        content: [
                            {
                                type: 'text',
                                text: `Calling ${state.sourceToolName} on MCP server ${state.serverName}...`,
                            },
                        ],
                    });

                    const result = (await server.client?.callTool(
                        {
                            name: state.sourceToolName,
                            arguments: params,
                        },
                        undefined,
                        {
                            signal,
                            resetTimeoutOnProgress: true,
                            onprogress: (progress: JsonObject) => {
                                onUpdate?.({
                                    content: [
                                        {
                                            type: 'text',
                                            text: summarizeProgress(progress),
                                        },
                                    ],
                                    details: { progress },
                                });
                            },
                        },
                    )) as JsonObject | undefined;

                    if (!result) {
                        throw new Error(
                            `MCP tool ${state.sourceToolName} returned no result.`,
                        );
                    }

                    const formatted = await formatToolResult(
                        result,
                        state.serverName,
                        state.sourceToolName,
                    );
                    if (result.isError === true) {
                        throw new Error(formatted.text);
                    }

                    return {
                        content: [{ type: 'text', text: formatted.text }],
                        details: formatted.details,
                    };
                },
            });
        }
    }

    function statusLines(): string[] {
        const lines: string[] = [];

        if (registrationState.configPath) {
            lines.push(`config: ${registrationState.configPath}`);
        } else {
            lines.push('config: not found (.mcp.json or opencode.json)');
        }

        for (const [serverName, server] of serverStates.entries()) {
            if (server.error) {
                lines.push(`${serverName}: error - ${server.error}`);
                continue;
            }

            lines.push(`${serverName}: ${server.tools.length} tools loaded`);
        }

        for (const error of registrationState.loadErrors) {
            if (!lines.includes(error)) {
                lines.push(`load error: ${error}`);
            }
        }

        if (toolStates.size > 0) {
            lines.push(`registered tools: ${toolStates.size}`);
        }

        return lines;
    }

    pi.registerCommand('mcp-status', {
        description: 'Show MCP bridge status',
        handler: async (_args: string, ctx: PiReloadContext) => {
            const message = statusLines().join('\n');
            if (ctx.hasUI) {
                ctx.ui.notify(
                    `MCP bridge loaded. See terminal for details.`,
                    'info',
                );
            }
            console.log(message);
        },
    });

    pi.registerCommand('mcp-reload', {
        description: 'Reload pi resources and MCP bridge',
        handler: async (_args: string, ctx: PiReloadContext) => {
            await ctx.reload();
            return;
        },
    });

    pi.on(
        'session_start',
        async (_event: JsonObject, ctx: PiSessionContext) => {
            await loadServers(ctx.cwd);
            registerTools();

            if (ctx.hasUI) {
                const successCount = Array.from(serverStates.values()).filter(
                    (server) => !server.error,
                ).length;
                const toolCount = toolStates.size;
                ctx.ui.notify(
                    `MCP bridge ready: ${successCount} servers, ${toolCount} tools.`,
                    registrationState.loadErrors.length > 0
                        ? 'warning'
                        : 'info',
                );
            }
        },
    );

    pi.on('session_shutdown', async () => {
        await cleanupServers();
    });
}
