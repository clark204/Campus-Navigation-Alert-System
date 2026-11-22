import { useEffect, useState } from 'react';

import { Button } from './ui';

/**
 * "Resend code" with the wait between codes (EmailCodes::RESEND_SECONDS, 60 s); the server enforces it too.
 * Counts down from waitSeconds; the parent gives it a new `key` after each send to start again.
 */
export function ResendButton({ waitSeconds, onPress, busy }: { waitSeconds: number; onPress: () => void; busy: boolean }) {
  const [left, setLeft] = useState(waitSeconds);

  useEffect(() => {
    if (left <= 0) return;
    const timer = setTimeout(() => setLeft((seconds) => seconds - 1), 1000);
    return () => clearTimeout(timer);
  }, [left]);

  return (
    <Button
      title={left > 0 ? `Resend code in ${left} s` : 'Resend code'}
      variant="quiet"
      loading={busy}
      loadingTitle="Sending…"
      disabled={left > 0}
      onPress={onPress}
    />
  );
}
