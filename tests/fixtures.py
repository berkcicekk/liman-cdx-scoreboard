"""Local protocol fixtures. Never used by the deployed extension."""
import http.server
import socketserver
import socket
import ssl
import struct
import threading
import time
import os

class HTTP(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_GET(self):
        if self.path == '/slow': time.sleep(2)
        status = 503 if self.path == '/down' else 200
        if self.path == '/redirect':
            self.send_response(302)
            self.send_header('Location', 'http://192.0.2.1/')
            self.end_headers()
            return
        self.send_response(status)
        self.end_headers()
        try: self.wfile.write(b'healthy fixture')
        except BrokenPipeError: pass
    def do_POST(self):
        if self.path == '/auth-only':
            self.send_response(401)
            self.send_header('WWW-Authenticate', 'Negotiate')
            self.end_headers()
            return
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b'<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body><w:IdentifyResponse xmlns:w="http://schemas.dmtf.org/wbem/wsman/identity/1/wsmanidentity.xsd"><w:ProtocolVersion>1</w:ProtocolVersion></w:IdentifyResponse></s:Body></s:Envelope>')

class SSH(socketserver.BaseRequestHandler):
    def handle(self): self.request.sendall(b'SSH-2.0-TestFixture\r\n')
class Slow(socketserver.BaseRequestHandler):
    def handle(self): time.sleep(10)
class FakeSSH(socketserver.BaseRequestHandler):
    def handle(self): self.request.sendall(b'HTTP/1.1 200 OK\r\n')
class DNS(socketserver.BaseRequestHandler):
    def handle(self):
        data, sock = self.request
        qtype = struct.unpack('!H', data[-4:-2])[0]
        address = socket.inet_pton(socket.AF_INET6, '::1') if qtype == 28 else socket.inet_aton('127.0.0.1')
        header = data[:2] + struct.pack('!HHHHH',0x8180,1,1,0,0)
        answer = b'\xc0\x0c' + struct.pack('!HHIH',qtype,1,60,len(address)) + address
        sock.sendto(header + data[12:] + answer, self.client_address)

socketserver.TCPServer.allow_reuse_address = True
standard = os.environ.get('LS_FIXTURE_STANDARD_PORTS') == '1'
servers = [http.server.ThreadingHTTPServer(('127.0.0.1',80 if standard else 18080),HTTP),
           socketserver.ThreadingTCPServer(('127.0.0.1',22 if standard else 12222),SSH),
           socketserver.ThreadingTCPServer(('127.0.0.1',12223),Slow),
           socketserver.ThreadingTCPServer(('127.0.0.1',12224),FakeSSH),
           socketserver.ThreadingUDPServer(('127.0.0.1',15353),DNS)]
tls = http.server.ThreadingHTTPServer(('127.0.0.1',443 if standard else 18443),HTTP)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain('/tmp/ls-fixture.crt','/tmp/ls-fixture.key')
tls.socket = context.wrap_socket(tls.socket,server_side=True)
servers.append(tls)
for server in servers:
    thread = threading.Thread(target=server.serve_forever,daemon=True)
    thread.start()
print('READY',flush=True)
threading.Event().wait()
