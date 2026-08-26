#!/usr/bin/env python3
"""Captures the documentation screenshots through headless Chromium.

Usage, from inside a container that has chromium and can reach the instance:

    chromium --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage \
             --disable-crash-reporter --disable-crashpad --hide-scrollbars \
             --remote-debugging-port=9222 --user-data-dir=/tmp/chrome-prof about:blank &
    python3 seed-screenshots.py http://127.0.0.1 /tmp/shots \
        '[["01-rule-form.png", "/plugins/ticketclock/front/rule.form.php?id=1", true]]'

Seed the instance with `tools/seed-demo.php` first, and only ever on a demo database:
these screens show ticket titles, group names and user names.

The DevTools client is written out by hand because the throwaway container has no Node and
python3-minimal ships without http.client or any websocket package. It is shorter than
adding a dependency would be.
"""
import base64, json, os, re, socket, struct, sys, time

HOST, PORT = '127.0.0.1', 9222
WIDTH = 1440


def log(*a):
    print(*a, flush=True)


def http_get_json(host, port, path):
    """One GET, by hand.

    Chromium keeps the debugging connection open regardless of `Connection: close`, so
    this reads exactly Content-Length bytes rather than reading until EOF.
    """
    sock = socket.create_connection((host, port), timeout=10)
    sock.sendall(f"GET {path} HTTP/1.1\r\nHost: {host}:{port}\r\nAccept: */*\r\n\r\n".encode())
    buf = b''
    while b'\r\n\r\n' not in buf:
        buf += sock.recv(65536)
    head, body = buf.split(b'\r\n\r\n', 1)
    m = re.search(rb'Content-Length:\s*(\d+)', head, re.I)
    if m:
        want = int(m.group(1))
        while len(body) < want:
            body += sock.recv(65536)
        body = body[:want]
    sock.close()
    return json.loads(body.decode())


class WS:
    def __init__(self, url):
        rest = url[len('ws://'):]
        hostport, path = rest.split('/', 1)
        host, port = hostport.split(':')
        self.s = socket.create_connection((host, int(port)))
        self.s.settimeout(120)
        key = base64.b64encode(os.urandom(16)).decode()
        self.s.sendall((
            f"GET /{path} HTTP/1.1\r\nHost: {hostport}\r\nUpgrade: websocket\r\n"
            f"Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\n"
            f"Sec-WebSocket-Version: 13\r\n\r\n"
        ).encode())
        buf = b''
        while b'\r\n\r\n' not in buf:
            buf += self.s.recv(4096)
        self.buf = buf.split(b'\r\n\r\n', 1)[1]
        self.id = 0

    def _recv(self, n):
        while len(self.buf) < n:
            chunk = self.s.recv(1 << 20)
            if not chunk:
                raise IOError('socket closed')
            self.buf += chunk
        out, self.buf = self.buf[:n], self.buf[n:]
        return out

    def _frame(self, opcode, data):
        head = bytearray([0x80 | opcode])
        n = len(data)
        mask = os.urandom(4)
        if n < 126:
            head.append(0x80 | n)
        elif n < 1 << 16:
            head.append(0x80 | 126)
            head += struct.pack('>H', n)
        else:
            head.append(0x80 | 127)
            head += struct.pack('>Q', n)
        head += mask
        self.s.sendall(bytes(head) + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))

    def recv(self):
        acc, acc_op = b'', None
        while True:
            b1, b2 = self._recv(2)
            fin, opcode, n = b1 & 0x80, b1 & 0x0f, b2 & 0x7f
            if n == 126:
                n = struct.unpack('>H', self._recv(2))[0]
            elif n == 127:
                n = struct.unpack('>Q', self._recv(8))[0]
            payload = self._recv(n)
            if opcode == 8:
                raise IOError('closed by peer')
            if opcode == 9:            # ping -> pong, or chromium drops the socket
                self._frame(10, payload)
                continue
            if opcode == 10:
                continue
            if opcode in (1, 2):
                acc, acc_op = payload, opcode
            elif opcode == 0:
                acc += payload
            if fin:
                if acc_op == 1:
                    return json.loads(acc.decode())
                acc, acc_op = b'', None

    def call(self, method, **params):
        self.id += 1
        mid = self.id
        self._frame(1, json.dumps({'id': mid, 'method': method, 'params': params}).encode())
        while True:
            msg = self.recv()
            if msg.get('id') == mid:
                if 'error' in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get('result', {})


def target_ws():
    last = None
    for _ in range(60):
        try:
            for t in http_get_json(HOST, PORT, '/json'):
                if t['type'] == 'page':
                    return t['webSocketDebuggerUrl']
        except Exception as e:
            last = e
        time.sleep(0.5)
    raise SystemExit(f'chromium never answered on the debugging port: {last!r}')


def evaluate(ws, expr):
    r = ws.call('Runtime.evaluate', expression=expr, returnByValue=True, awaitPromise=True)
    return r.get('result', {}).get('value')


def viewport(ws, height):
    ws.call('Emulation.setDeviceMetricsOverride', width=WIDTH, height=height,
            deviceScaleFactor=2, mobile=False)


def goto(ws, url, settle=2.0):
    ws.call('Page.navigate', url=url)
    for _ in range(160):
        time.sleep(0.25)
        if evaluate(ws, 'document.readyState') == 'complete':
            break
    time.sleep(settle)


def shoot(ws, path, full=True):
    """Capture at a fixed width.

    The viewport is re-asserted before every capture: navigating resets it, and a shot
    taken at the default 800x600 crops the wide tables these screens render.
    """
    viewport(ws, 900)
    time.sleep(0.8)
    if full:
        m = ws.call('Page.getLayoutMetrics')
        viewport(ws, min(int(m['cssContentSize']['height']) + 20, 5000))
        time.sleep(0.8)
    r = ws.call('Page.captureScreenshot', format='png')
    with open(path, 'wb') as fh:
        fh.write(base64.b64decode(r['data']))
    log('wrote', path, os.path.getsize(path), 'bytes')


def main():
    base, outdir, shots = sys.argv[1], sys.argv[2], json.loads(sys.argv[3])
    os.makedirs(outdir, exist_ok=True)
    ws = WS(target_ws())
    ws.call('Page.enable')
    ws.call('Runtime.enable')
    viewport(ws, 900)
    log('connected')

    goto(ws, base + '/index.php')
    evaluate(ws, """
        (function () {
            document.querySelector('input[name="login_name"]').value = %s;
            document.querySelector('input[name="login_password"]').value = %s;
            // Click the button rather than calling form.submit(): the button carries
            // name="submit", and GLPI's login controller looks for that field.
            document.querySelector('form[action*="login.php"] button[type="submit"]').click();
            return true;
        })()
    """ % (json.dumps(os.environ.get('GLPI_LOGIN', 'glpi')),
           json.dumps(os.environ.get('GLPI_PASSWORD', 'glpi'))))
    time.sleep(5)
    log('after login:', evaluate(ws, 'document.title'))

    for name, url, full in shots:
        log('navigating', url)
        goto(ws, base + url)
        shoot(ws, os.path.join(outdir, name), full)


if __name__ == '__main__':
    main()
