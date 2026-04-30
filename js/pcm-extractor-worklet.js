/**
 * AudioWorkletProcessor that converts the input audio frames to little-endian
 * PCM 16-bit at the AudioContext sample rate (we run the context at 16 kHz so
 * we feed Gemini Live exactly what it expects).
 *
 * Frames are chunked to ~250ms (4000 samples at 16 kHz) before being posted
 * back to the main thread, balancing WebSocket overhead and latency.
 */
class PCMExtractor extends AudioWorkletProcessor {
    constructor() {
        super();
        this.buffer = new Int16Array(0);
        this.targetSize = 4000; // 250ms @ 16kHz
    }

    process(inputs) {
        const input = inputs[0];
        if (!input || input.length === 0) return true;
        const channel = input[0];
        if (!channel || channel.length === 0) return true;

        const converted = new Int16Array(channel.length);
        for (let i = 0; i < channel.length; i++) {
            const s = Math.max(-1, Math.min(1, channel[i]));
            converted[i] = s < 0 ? s * 0x8000 : s * 0x7FFF;
        }

        // Append to internal buffer
        const merged = new Int16Array(this.buffer.length + converted.length);
        merged.set(this.buffer, 0);
        merged.set(converted, this.buffer.length);
        this.buffer = merged;

        // Flush in fixed-size chunks
        while (this.buffer.length >= this.targetSize) {
            const chunk = this.buffer.slice(0, this.targetSize);
            this.buffer = this.buffer.slice(this.targetSize);
            this.port.postMessage(chunk.buffer, [chunk.buffer]);
        }

        return true;
    }
}

registerProcessor('pcm-extractor', PCMExtractor);
