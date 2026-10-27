"""Real Apache rewrite, legacy POST, CSRF and cookie-scope regression checks."""
import http.cookiejar
import json
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = 'http://127.0.0.1:8789'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def client():
    return urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def request(opener, path, fields=None, body=None, content_type=None):
    if fields is not None:
        body = urllib.parse.urlencode(fields).encode()
        content_type = 'application/x-www-form-urlencoded'
    req = urllib.request.Request(ROOT + path, data=body, headers={'Content-Type':content_type} if content_type else {})
    try:
        response = opener.open(req, timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.code, response.headers, response.read().decode()

for attempt in range(60):
    try:
        request(client(), '/admin/')
        break
    except (urllib.error.URLError, TimeoutError):
        time.sleep(.5)
else:
    raise AssertionError('Apache did not start.')

for base in ['/', '/shop/']:
    status, headers, body = request(client(), base + 'robots.txt')
    assert status == 200 and 'text/plain' in headers['Content-Type']
    assert 'Disallow: ' + base + 'admin' in body and 'Set-Cookie' not in headers
    status, headers, body = request(client(), base + 'sitemap.xml')
    assert status == 503 and headers['Retry-After'] == '300' and 'Set-Cookie' not in headers
    assert not body.strip(), 'Discovery failure leaked diagnostic/configuration details.'
    for area in ['admin', 'account']:
        opener = client()
        old = base + area + '.php'
        root = base + area + '/'
        status, headers, _ = request(opener, old + '?mode=reset&token=abc123')
        assert status == 302 and headers['Location'] == root + 'reset-password/?token=abc123'
        assert 'no-store' in headers['Cache-Control']
        status, cookie_headers, body = request(opener, root + 'reset-password/?token=abc123')
        data = json.loads(body)
        assert status == 200 and data['query'] == {'token':'abc123','mode':'reset'} and data['base'] == base
        status, headers, body = request(opener, root)
        data = json.loads(body)
        assert status == 200 and not data['signed']
        assert ('path=' + base) in cookie_headers['Set-Cookie'] and 'HttpOnly' in cookie_headers['Set-Cookie']
        status, headers, _ = request(opener, old, {'action':'login','csrf':data['csrf'], 'email':area+'@example.test','password':'test-password-123'})
        assert status == 303 and headers['Location'] == root, 'Old POST login must run once and retain its body.'
        status, _, body = request(opener, root + 'orders/?id=7')
        data = json.loads(body)
        assert status == 200 and data['signed'] and data['query'] == {'id':'7','section':'orders'}
        assert request(opener, root+'orders/', {'csrf':'bad','action':'probe'})[0] == 403
        token=data['csrf']
        boundary='panel-test-boundary'
        body=''.join('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+value+'\r\n' for key,value in [('csrf',token),('action','probe'),('marker','A&B+č')])
        body+='--'+boundary+'\r\nContent-Disposition: form-data; name="upload"; filename="sample.txt"\r\nContent-Type: text/plain\r\n\r\nupload-test-123\r\n--'+boundary+'--\r\n'
        for target in [old+'?section=orders',root+'orders/']:
            status, _, raw=request(opener,target,body=body.encode(),content_type='multipart/form-data; boundary='+boundary)
            result=json.loads(raw)
            assert status == 200 and result['method']=='POST' and result['upload']=='upload-test-123' and result['form']['marker']=='A&B+č'
        other='account' if area=='admin' else 'admin'
        status,_,raw=request(opener,base+other+'/')
        assert status == 200 and not json.loads(raw)['signed'], 'Roles shared an authentication cookie.'
        for suffix in ['unknown/','orders/extra/','orders//']:
            assert request(opener,root+suffix)[0] == 404
        assert request(opener,root.rstrip('/'))[1]['Location'] == root
    assert request(client(),base+'src/bootstrap.php')[0] == 403
    assert request(client(),base+'config/database.php')[0] == 403
print('Apache panel routes, legacy links/uploads, CSRF and role cookies passed at root and /shop/.')
