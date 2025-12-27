import { WorkspaceType } from '@prisma/client';

interface Message {
  role: string;
  content: string;
}

interface MockResponse {
  content: string;
  model: string;
  tokenCount: number;
}

// Mock AI responses based on workspace type and rules
export async function generateMockResponse(
  messages: Message[],
  workspaceType: WorkspaceType,
  rules: Record<string, unknown>[]
): Promise<MockResponse> {
  const lastUserMessage = [...messages].reverse().find(m => m.role === 'USER');
  const userContent = lastUserMessage?.content || '';

  // Simulate processing delay
  await new Promise(resolve => setTimeout(resolve, 500 + Math.random() * 1000));

  // Generate context-aware response based on workspace type
  let response = '';
  let model = 'mock-ai-v1';

  switch (workspaceType) {
    case 'NORMAL':
      response = generateNormalResponse(userContent, messages);
      model = 'mock-general-v1';
      break;
    case 'CHURCH':
      response = generateChurchResponse(userContent, messages);
      model = 'mock-church-v1';
      break;
    case 'CODER':
      response = generateCoderResponse(userContent, messages);
      model = 'mock-coder-v1';
      break;
    default:
      response = generateNormalResponse(userContent, messages);
  }

  // Apply any rules that might modify the response
  response = applyRules(response, rules);

  return {
    content: response,
    model,
    tokenCount: Math.ceil(response.length / 4), // Rough token estimate
  };
}

function generateNormalResponse(userMessage: string, messages: Message[]): string {
  const responses = [
    `I understand you're asking about "${userMessage.substring(0, 50)}${userMessage.length > 50 ? '...' : ''}". Let me help you with that.

This is a mock response for testing purposes. In production, this would be handled by a real AI model.

Based on our conversation so far (${messages.length} messages), I can provide the following insights:

1. **Understanding the Context**: I've analyzed your message and the conversation history.
2. **Generating a Response**: In a real scenario, I would process this through an AI model.
3. **Providing Value**: The actual response would be much more helpful and contextual.

Is there anything specific you'd like me to clarify?`,

    `Thank you for your message! I'm a mock AI assistant designed for testing the application flow.

Here's what I understood from your query:
- Topic: ${extractTopic(userMessage)}
- Message length: ${userMessage.length} characters
- Conversation depth: ${messages.length} messages

In production, you would receive a thoughtful, contextual response from a real AI model. This mock response helps verify that:
✓ Messages are being saved correctly
✓ The chat interface is working
✓ Responses are displayed properly

Let me know if you need anything else!`,

    `Hello! I've received your message and I'm here to help.

**Mock Response Mode Active**

This system is currently running with mock AI responses for testing purposes. Your actual message was:

> ${userMessage.length > 200 ? userMessage.substring(0, 200) + '...' : userMessage}

Features being tested:
- Message persistence
- Thread management
- Response generation
- UI rendering

Feel free to continue the conversation to test more features!`,
  ];

  return responses[Math.floor(Math.random() * responses.length)];
}

function generateChurchResponse(userMessage: string, messages: Message[]): string {
  const responses = [
    `Thank you for reaching out in the Church workspace.

I've received your message about "${userMessage.substring(0, 50)}${userMessage.length > 50 ? '...' : ''}".

**Mock Church Assistant Active**

This is a test response designed for the church workspace. In production, responses in this space would be:
- Thoughtful and encouraging
- Aligned with spiritual values
- Focused on community support

Conversation context: ${messages.length} messages exchanged.

How may I continue to assist you?`,

    `Blessings! I'm here to help in the Church workspace.

Your message has been received and processed (mock mode).

**Key points from your message:**
- Topic: ${extractTopic(userMessage)}
- Purpose: Testing church workspace functionality

In the full implementation, this workspace would provide:
✓ Sermon planning assistance
✓ Community event coordination
✓ Spiritual resource recommendations
✓ Pastoral care support tools

Feel free to test more features!`,
  ];

  return responses[Math.floor(Math.random() * responses.length)];
}

function generateCoderResponse(userMessage: string, messages: Message[]): string {
  // Check if the message looks like code
  const hasCodeIndicators = /```|function|class|import|export|const|let|var|if\s*\(|for\s*\(|while\s*\(/.test(userMessage);

  if (hasCodeIndicators) {
    return `**Mock Coder Assistant**

I see you've shared some code or a coding question. Here's my analysis:

\`\`\`javascript
// Mock code analysis response
// In production, this would provide:
// - Code review feedback
// - Bug detection
// - Optimization suggestions
// - Best practice recommendations

console.log("Testing code workspace functionality");
\`\`\`

**Detected in your message:**
- Code patterns: ${hasCodeIndicators ? 'Yes' : 'No'}
- Message length: ${userMessage.length} characters
- Conversation depth: ${messages.length} messages

**Mock Features:**
- Syntax highlighting works ✓
- Code blocks render properly ✓
- Technical formatting preserved ✓

Would you like me to elaborate on any aspect of the code?`;
  }

  const responses = [
    `**Coder Workspace - Mock Response**

I've processed your technical query: "${userMessage.substring(0, 80)}${userMessage.length > 80 ? '...' : ''}"

This is a mock response for the coder workspace. In production, this assistant would provide:

1. **Code Generation** - Write code in multiple languages
2. **Debugging Help** - Identify and fix issues
3. **Architecture Advice** - Design patterns and best practices
4. **Documentation** - Generate docs and comments
5. **Testing** - Write unit and integration tests

Current conversation: ${messages.length} messages

Feel free to paste code snippets to test the formatting!`,

    `Hello, developer! 👨‍💻

Your message has been received in the Coder workspace.

**Mock Analysis:**
\`\`\`
Topic: ${extractTopic(userMessage)}
Type: Technical query
Mode: Testing
\`\`\`

In production, you would receive:
- Detailed code explanations
- Working code examples
- Performance optimization tips
- Security recommendations

Test the code formatting by sharing some code!`,
  ];

  return responses[Math.floor(Math.random() * responses.length)];
}

function extractTopic(message: string): string {
  // Simple topic extraction - just take first few meaningful words
  const words = message
    .toLowerCase()
    .replace(/[^a-z\s]/g, '')
    .split(/\s+/)
    .filter(w => w.length > 3)
    .slice(0, 3);

  return words.length > 0 ? words.join(' ') : 'general inquiry';
}

function applyRules(response: string, rules: Record<string, unknown>[]): string {
  // In production, rules would modify the response behavior
  // For mock purposes, just add a rules indicator if rules exist

  if (rules.length > 0) {
    response += `\n\n---\n*${rules.length} ruleset(s) applied to this response*`;
  }

  return response;
}

// Generate a simple greeting response
export function generateGreeting(workspaceType: WorkspaceType): string {
  switch (workspaceType) {
    case 'NORMAL':
      return "Hello! I'm your AI assistant (mock mode). How can I help you today?";
    case 'CHURCH':
      return "Blessings! I'm your Church workspace assistant (mock mode). How may I serve you today?";
    case 'CODER':
      return "Hey there, developer! I'm your coding assistant (mock mode). Ready to write some code?";
    default:
      return "Hello! I'm your AI assistant (mock mode). How can I help?";
  }
}
