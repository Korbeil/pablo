"""A tiny stdio MCP server for testing McpClient against a real subprocess.

Reads newline-delimited JSON-RPC on stdin. Answers `initialize`, ignores
notifications, and serves `tools/call` from the FAKE_MCP_RESPONSES env var:
a JSON object mapping tool name → result content object. Special tool
names: "sleepy" never answers (for timeout tests), "broken" answers with
isError content.
"""

import json
import os
import sys
import time

RESPONSES = json.loads(os.environ.get("FAKE_MCP_RESPONSES", "{}"))


def reply(payload):
    sys.stdout.write(json.dumps(payload) + "\n")
    sys.stdout.flush()


for line in sys.stdin:
    line = line.strip()
    if not line:
        continue
    msg = json.loads(line)
    if "id" not in msg:  # notification
        continue
    if msg["method"] == "initialize":
        reply(
            {
                "jsonrpc": "2.0",
                "id": msg["id"],
                "result": {
                    "protocolVersion": "2024-11-05",
                    "capabilities": {"tools": {}},
                    "serverInfo": {"name": "fake", "version": "0"},
                },
            }
        )
        continue
    if msg["method"] == "tools/call":
        tool = msg["params"]["name"]
        if tool == "sleepy":
            time.sleep(60)
            continue
        if tool == "broken":
            reply(
                {
                    "jsonrpc": "2.0",
                    "id": msg["id"],
                    "result": {
                        "isError": True,
                        "content": [{"type": "text", "text": "tool exploded"}],
                    },
                }
            )
            continue
        result = RESPONSES.get(tool, {"echo": tool})
        reply(
            {
                "jsonrpc": "2.0",
                "id": msg["id"],
                "result": {
                    "content": [{"type": "text", "text": json.dumps(result)}]
                },
            }
        )
        continue
    reply(
        {
            "jsonrpc": "2.0",
            "id": msg["id"],
            "error": {"code": -32601, "message": f"unknown method {msg['method']}"},
        }
    )
