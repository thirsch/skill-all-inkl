"""Exercise the real PHP web endpoint in a temporary, local-only PHP server."""
import base64
import concurrent.futures
import json
from pathlib import Path
import shutil
import os
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

ASSETS = Path(__file__).resolve().parents[1] / 'assets' / 'health'


@unittest.skipUnless(shutil.which('php'), 'PHP 8.5 CLI required')
class HealthcheckTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        (self.root / 'public').mkdir()
        (self.root / 'cache').mkdir()
        (self.root / 'health-cache').mkdir()
        auth_hash = subprocess.check_output(['php', '-r', "echo password_hash('test-secret', PASSWORD_BCRYPT, ['cost'=>4]);"],text=True)
        (self.root / 'health-auth.json').write_text(json.dumps({'username':'healthcheck','password_hash':auth_hash}))
        shutil.copy(ASSETS / 'healthcheck.php', self.root / 'healthcheck.php')
        shutil.copy(ASSETS / 'public-health.php', self.root / 'public/health.php')
        (self.root / 'release.json').write_text(json.dumps({'format': 1, 'id': 'health-test'}))
        self.config = {'php_minor': '8.5', 'required_extensions': ['json'], 'optional_extensions': [],
                       'extension_alternatives': {}, 'writable_directories': {}, 'database': None,
                       'auth_file':str(self.root/'health-auth.json'), 'cache_directory':str(self.root/'health-cache'),
                       'cache_ttl_seconds':1}
        self.save()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        self.url = f'http://127.0.0.1:{port}/health.php'
        self.server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(self.root / 'public')],
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                       env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4'),start_new_session=True)
        self.addCleanup(self.cleanup)
        for _ in range(60):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1):
                    break
            except OSError:
                time.sleep(.05)
        else:
            self.fail('PHP test server failed to start')

    def cleanup(self):
        import signal
        os.killpg(self.server.pid, signal.SIGTERM)
        self.server.wait(timeout=5)
        self.tmp.cleanup()

    def save(self, php_extra=''):
        state = self.root / 'health-cache/health-state.json'
        if state.exists():
            state.unlink()
        payload = base64.b64encode(json.dumps(self.config).encode()).decode()
        (self.root / 'health-config.php').write_text(
            "<?php $config = json_decode(base64_decode('" + payload + "'), true);\n" + php_extra + '\nreturn $config;')

    def fetch(self, status, credentials='healthcheck:test-secret'):
        request=urllib.request.Request(self.url)
        if credentials is not None:
            request.add_header('Authorization','Basic '+base64.b64encode(credentials.encode()).decode())
        try:
            response = urllib.request.urlopen(request, timeout=3)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            raw = response.read().decode()
            self.assertEqual(response.status, status, raw)
            self.assertIn('no-store', response.headers['Cache-Control'])
            if status == 401:
                self.assertTrue(response.headers['WWW-Authenticate'].startswith('Basic '))
            self.assertNotIn(str(self.root), raw)
            return json.loads(raw)

    def test_required_optional_and_web_runtime(self):
        report = self.fetch(200)
        self.assertEqual(report['release'], 'health-test')
        self.assertEqual(report['sapi'], 'cli-server')
        self.assertEqual(report['checks']['extension:json'], 'ok')
        self.config['optional_extensions'] = ['codex_missing_extension_probe']
        self.save()
        self.assertEqual(self.fetch(200)['checks']['extension:codex_missing_extension_probe'], 'warning')
        self.config['required_extensions'].append('codex_missing_extension_probe')
        self.save()
        self.assertEqual(self.fetch(503)['checks']['extension:codex_missing_extension_probe'], 'failed')

    def test_alternatives_and_php_target(self):
        self.config['extension_alternatives'] = {'parser': ['json', 'codex_missing_extension_probe']}
        self.save()
        self.assertEqual(self.fetch(200)['checks']['extension-group:parser'], 'ok')
        self.config['extension_alternatives']['parser'] = ['codex_missing_extension_probe']
        self.save()
        self.assertEqual(self.fetch(503)['checks']['extension-group:parser'], 'failed')
        self.config['extension_alternatives'] = {}
        self.config['php_minor'] = '0.0'
        self.save()
        self.assertEqual(self.fetch(503)['checks']['php'], 'failed')

    def test_directories_do_not_expose_paths(self):
        self.config['writable_directories'] = {'cache': str(self.root / 'cache')}
        self.save()
        self.assertEqual(self.fetch(200)['checks']['directory:cache'], 'ok')
        self.config['writable_directories']['cache'] = str(self.root / 'missing')
        self.save()
        self.assertEqual(self.fetch(503)['checks']['directory:cache'], 'failed')
        self.config['writable_directories']['cache'] = str(self.root / 'release.json')
        self.save()
        self.assertEqual(self.fetch(503)['checks']['directory:cache'], 'failed')

    def test_database_query_and_sanitized_failure(self):
        self.save("$config['database'] = static fn(): PDO => new PDO('sqlite::memory:');")
        self.assertEqual(self.fetch(200)['checks']['database'], 'ok')
        self.save("$config['database'] = static function () { throw new RuntimeException('secret-password-and-dsn'); };")
        report = self.fetch(503)
        self.assertEqual(report['checks']['database'], 'failed')
        self.assertNotIn('secret-password-and-dsn', json.dumps(report))

    def test_malformed_configuration_fails_closed(self):
        self.config['required_extensions'] = 'json'
        self.save()
        self.assertEqual(self.fetch(503)['checks'], {'health_configuration': 'failed'})
        (self.root / 'health-config.php').write_text('<?php broken syntax @@@')
        self.assertEqual(self.fetch(503)['status'], 'failed')

    def test_authentication_precedes_probes_and_cache(self):
        counter=self.root/'probe-count'
        self.save("$config['database'] = static function (): PDO { file_put_contents(__DIR__.'/probe-count', 'x', FILE_APPEND); return new PDO('sqlite::memory:'); };")
        self.assertEqual(self.fetch(401,None),{'status':'unauthorized'})
        self.fetch(401,'healthcheck:wrong')
        self.assertFalse(counter.exists())
        self.fetch(200)
        self.assertEqual(counter.read_text(),'x')
        self.fetch(401,None)
        self.assertEqual(counter.read_text(),'x')

    def test_concurrent_requests_single_probe_and_expiry(self):
        counter=self.root/'probe-count'
        self.save("$config['database'] = static function (): PDO { file_put_contents(__DIR__.'/probe-count', 'x', FILE_APPEND); return new PDO('sqlite::memory:'); };")
        first=self.fetch(200)
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
            reports=list(executor.map(lambda _:self.fetch(200),range(16)))
        self.assertEqual(counter.read_text(),'x')
        self.assertTrue(all(report['check_id']==first['check_id'] for report in reports))
        time.sleep(1.1)
        second=self.fetch(200)
        self.assertNotEqual(first['check_id'],second['check_id'])
        self.assertEqual(counter.read_text(),'xx')

    def test_failure_cached_and_release_change_does_not_bypass_interval(self):
        counter=self.root/'probe-count'
        self.save("$config['database'] = static function () { file_put_contents(__DIR__.'/probe-count', 'x', FILE_APPEND); throw new RuntimeException('secret'); };")
        first=self.fetch(503)
        self.assertEqual(first['checks']['database'],'failed')
        self.assertEqual(self.fetch(503)['check_id'],first['check_id'])
        self.assertEqual(counter.read_text(),'x')
        (self.root/'release.json').write_text(json.dumps({'format':1,'id':'next-release'}))
        pending=self.fetch(503)
        self.assertEqual(pending['release'],'next-release')
        self.assertEqual(pending['checks'],{'health_refresh':'pending'})
        self.assertEqual(counter.read_text(),'x')
        time.sleep(1.1)
        refreshed=self.fetch(503)
        self.assertEqual(refreshed['release'],'next-release')
        self.assertNotEqual(refreshed['check_id'],first['check_id'])
        self.assertEqual(counter.read_text(),'xx')

    def test_cold_cache_parallel_workers_do_not_duplicate_probe(self):
        self.config['cache_ttl_seconds']=10
        self.save("$config['database'] = static function (): PDO { file_put_contents(__DIR__.'/probe-count', 'x', FILE_APPEND); usleep(250000); return new PDO('sqlite::memory:'); };")
        def request(_):
            req=urllib.request.Request(self.url,headers={'Authorization':'Basic '+base64.b64encode(b'healthcheck:test-secret').decode()})
            try:
                response=urllib.request.urlopen(req,timeout=3)
            except urllib.error.HTTPError as error:
                response=error
            with response:
                return response.status,json.loads(response.read())
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
            responses=list(executor.map(request,range(16)))
        self.assertTrue(all(code in (200,503) for code,_ in responses))
        self.assertTrue(any(code==200 for code,_ in responses))
        self.assertEqual((self.root/'probe-count').read_text(),'x')
        first=self.fetch(200)
        time.sleep(1.1)
        self.assertEqual(self.fetch(200)['check_id'],first['check_id'])
        self.assertEqual((self.root/'probe-count').read_text(),'x')


if __name__ == '__main__':
    unittest.main(verbosity=2)
