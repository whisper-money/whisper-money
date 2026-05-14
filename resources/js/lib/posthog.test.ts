import { describe, expect, it } from 'vitest';
import { isPostHogSessionRecordingEnabled } from './posthog';

describe('isPostHogSessionRecordingEnabled', () => {
    it('keeps session recording disabled by default', () => {
        expect(isPostHogSessionRecordingEnabled()).toBe(false);
    });
});
