import { QRCodeSVG } from 'qrcode.react';

/** Side of the rendered code in px, including its quiet zone. */
export const INSTALL_QR_SIZE = 176;
/** Quiet zone in modules; the QR spec asks for 4, below that cameras scan unreliably. */
export const INSTALL_QR_MARGIN = 4;

/**
 * QR code for the current page, so a desktop/laptop visitor can scan it and
 * continue on their phone. Default export so InstallPrompt can lazy-load it —
 * the QR library only ships to the browsers that actually open the panel.
 */
export default function InstallQr({ url, size = INSTALL_QR_SIZE }: { url: string; size?: number }) {
  return (
    <div className="inline-block bg-white rounded-xl shadow-inner overflow-hidden leading-none">
      <QRCodeSVG
        title="QR code for this page"
        value={url}
        size={size}
        marginSize={INSTALL_QR_MARGIN}
        level="M"
        bgColor="#ffffff"
        fgColor="#000000"
      />
    </div>
  );
}
