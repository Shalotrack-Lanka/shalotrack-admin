// A tiny local stand-in for the C# API and the gateway during browser tests.
// It answers every request at once with "503 unavailable", on 127.0.0.1 only, and never forwards anything.
// Why: pointing the portal at a closed port is slow on Windows (about 2 seconds to fail per call, and every page
// makes several calls), while a stub that answers immediately keeps every page fast. The portal already treats an
// unavailable API or gateway as "no data", which is exactly what the tests want.
import http from 'node:http';

const PORT = Number(process.env.BROWSER_STUB_PORT || 8766);

http
    .createServer((req, res) => {
        req.resume();
        res.writeHead(503, { 'Content-Type': 'application/json', Connection: 'close' });
        res.end('{"message":"browser test stub: unavailable"}');
    })
    .listen(PORT, '127.0.0.1');
