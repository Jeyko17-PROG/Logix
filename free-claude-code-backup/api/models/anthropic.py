from __future__ import annotations
"""Anthropic API compatibility models."""

from typing import Any, Literal

from pydantic import BaseModel, Field


class ImageSource(BaseModel):
    type: Literal["base64"] = "base64"
    media_type: str
    data: str


class ContentBlockImage(BaseModel):
    """Explicit model for image content blocks expected by imports."""
    type: Literal["image"] = "image"
    source: ImageSource


class ContentBlockText(BaseModel):
    """Explicit model for text content blocks expected by imports."""
    type: Literal["text"] = "text"
    text: str


class ContentBlockThinking(BaseModel):
    """Explicit model for thinking/reasoning content blocks expected by imports."""
    type: Literal["thinking"] = "thinking"
    thinking: str
    signature: str | None = None


class ContentBlockToolUse(BaseModel):
    """Explicit model for tool use content blocks expected by imports."""
    type: Literal["tool_use"] = "tool_use"
    id: str
    name: str
    input: dict[str, Any]


class ContentBlockToolResult(BaseModel):
    """Explicit model for tool result content blocks expected by imports."""
    type: Literal["tool_result"] = "tool_result"
    tool_use_id: str
    content: str | list[ContentBlockText | ContentBlockImage] | None = None
    is_error: bool | None = None


class ContentBlock(BaseModel):
    type: Literal["text", "image", "tool_use", "tool_result", "thinking"]
    text: str | None = None
    source: ImageSource | None = None
    id: str | None = None
    name: str | None = None
    input: dict[str, Any] | None = None
    content: str | list[ContentBlock] | None = None
    is_error: bool | None = None
    thinking: str | None = None
    signature: str | None = None


class MessageParam(BaseModel):
    role: Literal["user", "assistant"]
    content: str | list[ContentBlock]


class ToolProperty(BaseModel):
    type: str
    description: str | None = None
    enum: list[str] | None = None
    items: dict[str, Any] | None = None


class ToolInputSchema(BaseModel):
    type: Literal["object"] = "object"
    properties: dict[str, ToolProperty] = Field(default_factory=dict)
    required: list[str] = Field(default_factory=list)


class ToolParam(BaseModel):
    name: str
    description: str
    input_schema: ToolInputSchema


class MetadataParam(BaseModel):
    user_id: str | None = None


class Usage(BaseModel):
    """Usage statistics for the message."""
    input_tokens: int = 0
    output_tokens: int = 0


class Message(BaseModel):
    """Explicit model for standard Anthropic Message response expected by imports."""
    id: str
    type: Literal["message"] = "message"
    role: Literal["assistant"] = "assistant"
    content: list[ContentBlock]
    model: str
    stop_reason: Literal["end_turn", "max_tokens", "stop_sequence", "tool_use"] | None = None
    stop_sequence: str | None = None
    usage: Usage


class MessagesRequest(BaseModel):
    model: str
    messages: list[MessageParam]
    system: str | list[ContentBlock] | None = None
    max_tokens: int
    metadata: MetadataParam | None = None
    stop_sequences: list[str] | None = None
    stream: bool = False
    temperature: float | None = None
    top_p: float | None = None
    top_k: int | None = None
    tools: list[ToolParam] | None = None

    def map_model(self) -> MessagesRequest:
        """Helper to return itself for architecture compatibility."""
        return self


class TokenCountRequest(BaseModel):
    model: str
    messages: list[MessageParam]
    system: str | list[ContentBlock] | None = None
    tools: list[ToolParam] | None = None


# --- TRUCO DE COMPATIBILIDAD DINÁMICA ---
# Si api/models/__init__.py intenta importar cualquier otra cosa (como 'Role', etc.),
# este fallback creará un alias dinámico para que no se caiga la inicialización.
def __getattr__(name: str) -> Any:
    if name == "Role":
        return Literal["user", "assistant", "system"]
    # Fallback genérico para cualquier otra clase tipográfica ausente
    return Any