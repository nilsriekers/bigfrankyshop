#!/usr/bin/env python3
"""
Generiert einen QR-Code für den Ticket-Scanner
"""
import qrcode
import sys

def generate_scanner_qr(domain):
    """Generiert QR-Code für Scanner-URL"""
    scanner_url = f"https://{domain}/wp-admin/admin-ajax.php?action=wjt_scanner_page"
    
    # QR-Code erstellen
    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_L,
        box_size=10,
        border=4,
    )
    qr.add_data(scanner_url)
    qr.make(fit=True)
    
    # Bild erstellen
    img = qr.make_image(fill_color="black", back_color="white")
    
    # Speichern
    filename = f"scanner-qr-{domain.replace('.', '-')}.png"
    img.save(filename)
    
    print(f"✅ QR-Code für Scanner gespeichert als: {filename}")
    print(f"📱 Scanner-URL: {scanner_url}")
    print(f"📋 Verwendung: Drucken Sie den QR-Code aus und geben Sie ihn dem Personal am Eingang")
    
    return filename

if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Verwendung: python3 generate-scanner-qr.py IHRE-DOMAIN.DE")
        print("Beispiel: python3 generate-scanner-qr.py meine-website.de")
        sys.exit(1)
    
    domain = sys.argv[1]
    generate_scanner_qr(domain)
