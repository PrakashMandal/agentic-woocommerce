"""
Core agent loop — wires TOOLS into the Claude API's tool-use loop.

Guardrail: update_stock (the only write tool) is intercepted HERE, not
in tools.py or wc_client.py. The model can request it, but a human
must explicitly confirm before it actually runs. Tools stay dumb and
mechanical; approval logic belongs in this orchestration layer.
"""

from __future__ import annotations

import json
import os

from anthropic import Anthropic
from dotenv import load_dotenv

from tools import TOOLS, dispatch_tool_call
from wc_client import WooCommerceClient

load_dotenv()

MODEL = os.environ.get("ANTHROPIC_MODEL", "claude-sonnet-5-5")
MAX_TOKENS = 2048

SYSTEM_PROMPT = (
    "You are an operations assistant for a WooCommerce clothing store. "
    "Use the provided tools to answer questions about products, orders, "
    "inventory, and sales trends. Be concise and cite real numbers from "
    "the tools — never guess at data you don't have a tool for."
)

WRITE_TOOLS = {"update_stock"}  # tools that require human confirmation before running


def confirm(tool_name: str, tool_input: dict) -> bool:
    print(f"\n[CONFIRM] Agent wants to call '{tool_name}' with: {json.dumps(tool_input)}")
    answer = input("Allow this? [y/N] ").strip().lower()
    return answer == "y"


def run_tool(client: WooCommerceClient, name: str, tool_input: dict) -> tuple[str, bool]:
    """Executes one tool call. Returns (result_text, is_error)."""
    if name in WRITE_TOOLS and not confirm(name, tool_input):
        return "Declined by user — no change was made.", True

    try:
        result = dispatch_tool_call(client, name, tool_input)
        return json.dumps(result), False
    except Exception as exc:  # noqa: BLE001 — a tool failure becomes a tool_result
        # the model can react to, not a crashed REPL.
        return f"Tool error: {exc}", True


def run_conversation_turn(client: WooCommerceClient, anthropic: Anthropic, messages: list[dict]) -> str:
    """Runs the tool_use loop until Claude produces a final text answer."""
    while True:
        response = anthropic.messages.create(
            model=MODEL,
            max_tokens=MAX_TOKENS,
            system=SYSTEM_PROMPT,
            tools=TOOLS,
            messages=messages,
        )
        messages.append({"role": "assistant", "content": response.content})

        if response.stop_reason != "tool_use":
            text_blocks = [block.text for block in response.content if block.type == "text"]
            return "\n".join(text_blocks)

        tool_results = []
        for block in response.content:
            if block.type != "tool_use":
                continue
            result_text, is_error = run_tool(client, block.name, block.input)
            tool_results.append(
                {
                    "type": "tool_result",
                    "tool_use_id": block.id,
                    "content": result_text,
                    "is_error": is_error,
                }
            )
        messages.append({"role": "user", "content": tool_results})


def main() -> None:
    client = WooCommerceClient()
    anthropic = Anthropic()
    messages: list[dict] = []

    print("WooCommerce agent ready. Type 'exit' to quit.\n")
    while True:
        user_input = input("You: ").strip()
        if user_input.lower() in {"exit", "quit"}:
            break
        if not user_input:
            continue

        messages.append({"role": "user", "content": user_input})
        answer = run_conversation_turn(client, anthropic, messages)
        print(f"\nAgent: {answer}\n")


if __name__ == "__main__":
    main()