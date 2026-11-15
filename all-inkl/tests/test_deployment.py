"""Run with Python 3 and PHP 8.5 + ZipArchive; all writes stay in temporary trees."""
import functools
import base64
import hashlib
import http.server
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import threading
import unittest
import zipfile
import fcntl

ASSETS = Path(__file__).resolve().parents[1] / 'assets' / 'deploy'


class QuietHandler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        expected = getattr(self.server, 'required_authorization', None)
        if expected and self.headers.get('Authorization') != expected:
            self.send_response(401)
            self.send_header('WWW-Authenticate','Basic realm="Healthcheck"')
            self.end_headers()
            return
        responses = getattr(self.server, 'health_responses', None)
        if responses:
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps(responses.pop(0)).encode())
            return
        super().do_GET()


class PackagingTests(unittest.TestCase):
    def test_package_and_reject_secrets_symlinks(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = root / 'source'
            (source / 'public').mkdir(parents=True)
            (source / 'public/index.html').write_text('ok')
            command = [sys.executable, str(ASSETS / 'package-release.py'), str(source)]
            def run(name):
                return subprocess.run(command + [str(root / name), 'r1'], capture_output=True, text=True)
            self.assertEqual(run('release.zip').returncode, 0)
            digest = hashlib.sha256((root / 'release.zip').read_bytes()).hexdigest()
            self.assertEqual((root / 'release.zip.sha256').read_text().strip(), digest)
            with zipfile.ZipFile(root / 'release.zip') as archive:
                self.assertEqual(json.loads(archive.read('release.json'))['id'], 'r1')
                self.assertEqual(archive.read('public/index.html'), b'ok')
            self.assertNotEqual(run('release.zip').returncode, 0)
            (source / '.env').write_text('secret')
            self.assertNotEqual(run('secret.zip').returncode, 0)
            self.assertFalse((root / 'secret.zip').exists())
            (source / '.env').unlink()
            (source / 'linked').symlink_to(source / 'public/index.html')
            self.assertNotEqual(run('symlink.zip').returncode, 0)
            (source / 'linked').unlink()
            (source / 'public/report:2026.txt').write_text('unsupported path')
            self.assertNotEqual(run('colon.zip').returncode, 0)
            self.assertFalse((root / 'colon.zip').exists())


@unittest.skipUnless(shutil.which('php'), 'PHP CLI required for deployment integration tests')
class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.base = Path(self.tmp.name)
        self.root = self.base / 'project'
        (self.root / 'shared').mkdir(parents=True)
        (self.root / 'shared/.env').write_text('KEEP_ME=yes')
        handler = functools.partial(QuietHandler, directory=str(self.root / 'current/public'))
        self.http = http.server.ThreadingHTTPServer(('127.0.0.1', 0), handler)
        self.thread = threading.Thread(target=self.http.serve_forever, daemon=True)
        self.thread.start()
        self.config = json.loads((ASSETS / 'config.example.json').read_text())
        self.config.update(root=str(self.root), health_url=f'http://127.0.0.1:{self.http.server_port}/index.php',
                           health_release_field='', health_check_id_field='', health_basic_auth_file='',
                           shared={'.env': '.env'}, health_attempts=1, health_successes=1, health_interval_seconds=0)
        self.config_path = self.base / 'config.json'
        self.save_config()

    def tearDown(self):
        self.http.shutdown()
        self.http.server_close()
        self.thread.join()
        self.tmp.cleanup()

    def save_config(self):
        self.config_path.write_text(json.dumps(self.config))

    def archive(self, release_id, extra=None, body='ok'):
        path = self.base / f'{release_id}.zip'
        with zipfile.ZipFile(path, 'w') as archive:
            archive.writestr('release.json', json.dumps({'format': 1, 'id': release_id}))
            archive.writestr('public/index.php', body)
            for name, value in (extra or {}).items():
                archive.writestr(name, value)
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        Path(str(path) + '.sha256').write_text(digest + '\n')
        return str(path), digest

    def call(self, action, *args, ok=True):
        result = subprocess.run(['php', str(ASSETS / 'deploy.php'), action, str(self.config_path), *args], capture_output=True, text=True)
        self.assertEqual(result.returncode == 0, ok, result.stdout + result.stderr)
        return result

    def current(self):
        return os.readlink(self.root / 'current')

    def test_install_activate_update_and_rollback(self):
        self.call('install', *self.archive('r1'))
        self.assertFalse((self.root / 'current').exists())
        self.call('activate', 'r1')
        self.assertEqual(self.current(), 'releases/r1')
        self.call('deploy', *self.archive('r2'))
        self.assertEqual(self.current(), 'releases/r2')
        self.assertEqual(os.readlink(self.root / 'previous'), 'releases/r1')
        self.assertEqual((self.root / 'current/.env').read_text(), 'KEEP_ME=yes')
        self.call('rollback', 'r1')
        self.assertEqual(self.current(), 'releases/r1')
        self.call('deploy', *self.archive('r1'), ok=False)
        self.assertEqual(self.current(), 'releases/r1')

    def test_packaged_releases_roundtrip(self):
        source = self.base / 'production'
        (source / 'public').mkdir(parents=True)
        for release_id in ['packed-r1', 'packed-r2']:
            (source / 'public/index.php').write_text('ok ' + release_id)
            output = self.base / 'dist' / release_id / 'release.zip'
            result = subprocess.run([sys.executable, str(ASSETS / 'package-release.py'),
                                     str(source), str(output), release_id], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.call('deploy', str(output), Path(str(output) + '.sha256').read_text().strip())
            self.assertEqual((self.root / 'current/public/index.php').read_text(), 'ok ' + release_id)
        self.call('rollback', 'packed-r1')
        self.assertEqual((self.root / 'current/public/index.php').read_text(), 'ok packed-r1')

    def test_health_requires_expected_release_and_stable_successes(self):
        self.config.update(health_release_field='release')
        self.save_config()
        self.call('deploy', *self.archive('stale-health', body='{"status":"ok","release":"old"}'), ok=False)
        self.config.update(health_attempts=3, health_successes=2)
        self.save_config()
        self.http.health_responses = [
            {'status': 'ok', 'release': 'old'},
            {'status': 'ok', 'release': 'fresh'},
            {'status': 'ok', 'release': 'fresh'},
        ]
        self.call('deploy', *self.archive('fresh'))
        self.assertEqual(self.http.health_responses, [])
        self.assertEqual(self.current(), 'releases/fresh')

    def test_authenticated_deployer_counts_distinct_probes(self):
        credentials=self.base/'health-client.json'
        credentials.write_text(json.dumps({'username':'healthcheck','password':'test-password'}))
        self.http.required_authorization='Basic '+base64.b64encode(b'healthcheck:test-password').decode()
        self.config.update(health_basic_auth_file=str(credentials),health_release_field='release',
                           health_check_id_field='check_id',health_attempts=4,health_successes=3)
        self.save_config()
        self.http.health_responses=[{'status':'ok','release':'authenticated','check_id':value} for value in ['a','a','b','c']]
        self.call('deploy',*self.archive('authenticated'))
        self.assertEqual(self.http.health_responses,[])
        self.http.health_responses=[{'status':'ok','release':'cached-only','check_id':'same'}]*4
        self.call('deploy',*self.archive('cached-only'),ok=False)

    def test_health_credentials_init_preserves_secrets(self):
        command=['php',str(ASSETS/'init-health-auth.php'),str(self.root/'shared'),'healthcheck']
        first=subprocess.run(command,capture_output=True,text=True,check=True)
        client_file=self.root/'shared/health-client.json'
        auth_file=self.root/'shared/health-auth.json'
        client=json.loads(client_file.read_text())
        auth=json.loads(auth_file.read_text())
        self.assertEqual(client['username'],'healthcheck')
        self.assertEqual(len(client['password']),64)
        self.assertNotIn(client['password'],first.stdout+first.stderr)
        self.assertNotIn('password',auth)
        self.assertEqual(stat.S_IMODE(client_file.stat().st_mode),0o600)
        self.assertEqual(stat.S_IMODE(auth_file.stat().st_mode),0o600)
        subprocess.run(command,capture_output=True,text=True,check=True)
        self.assertEqual(json.loads(client_file.read_text()),client)
        self.assertEqual(json.loads(auth_file.read_text()),auth)

    @unittest.skipUnless(hasattr(os, 'geteuid') and os.geteuid() == 0,
                         'Ownership failure fixture requires an isolated root test container')
    def test_health_credentials_reject_failed_permission_repair(self):
        with tempfile.TemporaryDirectory() as directory:
            shared = Path(directory)
            command = ['php', str(ASSETS/'init-health-auth.php'), str(shared), 'healthcheck']
            subprocess.run(command, capture_output=True, check=True)
            shared.chmod(0o755)
            for name in ('health-client.json', 'health-auth.json'):
                (shared/name).chmod(0o644)
            (shared/'.health-init.lock').chmod(0o666)
            (shared/'health-cache').chmod(0o777)
            result = subprocess.run(command, capture_output=True, text=True,
                                    preexec_fn=lambda: os.setuid(65534))
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn('ready', result.stdout)

    def test_checksum_and_archive_path_attacks(self):
        path, digest = self.archive('bad-hash')
        self.call('deploy', path, '0' * 64, ok=False)
        for number, name in enumerate(['../escape', '/absolute', 'public/../../escape', 'public\\escape', '.env', '.deploy-ready.json']):
            self.call('deploy', *self.archive(f'bad-{number}', {name: 'bad'}), ok=False)
        symlink = zipfile.ZipInfo('public/link')
        symlink.create_system = 3
        symlink.external_attr = (stat.S_IFLNK | 0o777) << 16
        self.call('deploy', *self.archive('bad-symlink', {symlink: '../../outside'}), ok=False)
        self.assertFalse((self.root / 'current').exists())
        self.assertEqual(list((self.root / 'releases').iterdir()), [])

    def test_shared_hook_and_health_failures(self):
        self.call('deploy', *self.archive('good'))
        self.config['shared'] = {'.env': 'missing'}
        self.save_config()
        self.call('deploy', *self.archive('missing-shared'), ok=False)
        self.assertEqual(self.current(), 'releases/good')
        self.config['shared'] = {'.env': '.env'}
        self.config['prepare'] = [['php', '-r', 'exit(7);']]
        self.save_config()
        self.call('deploy', *self.archive('failed-hook'), ok=False)
        self.call('activate', 'failed-hook', ok=False)
        self.assertEqual(self.current(), 'releases/good')
        self.config['prepare'] = [['php', '-r', 'file_put_contents("prepared", "yes");']]
        self.save_config()
        result = self.call('deploy', *self.archive('failed-health', body='unhealthy'), ok=False)
        self.assertIn('AFTER activation', result.stderr)
        self.assertEqual(self.current(), 'releases/failed-health')
        self.assertEqual((self.root / 'current/prepared').read_text(), 'yes')
        self.call('rollback', 'good')

    def test_lock_limits_version_and_existing_directory(self):
        archive = self.archive('locked')
        with (self.root / '.deploy.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            self.assertIn('Another deployment', self.call('deploy', *archive, ok=False).stderr)
        self.config['max_unpacked_bytes'] = 1
        self.save_config()
        self.call('deploy', *archive, ok=False)
        self.config['max_unpacked_bytes'] = 100000
        self.config['php_minor'] = '0.0'
        self.save_config()
        self.call('deploy', *archive, ok=False)
        self.config['php_minor'] = '8.5'
        self.save_config()
        (self.root / 'current').mkdir()
        self.call('deploy', *archive, ok=False)
        self.assertFalse((self.root / 'current').is_symlink())
        self.assertEqual(list((self.root / 'releases').iterdir()), [])

    def test_ssh_wrapper_upload_and_activate_via_fake_transport(self):
        # Only the transport is mocked; execute the actual remote shell and PHP commands.
        bin_dir = self.base / 'bin'
        bin_dir.mkdir()
        ssh = bin_dir / 'ssh'
        ssh.write_text('#!/usr/bin/env python3\nimport os,sys\nassert "StrictHostKeyChecking=yes" in sys.argv\nassert "BatchMode=yes" in sys.argv\nos.execv("/bin/sh", ["sh", "-c", sys.argv[-1]])\n')
        ssh.chmod(0o755)
        (bin_dir / 'php85').symlink_to(shutil.which('php'))
        (self.root / 'deploy').mkdir()
        shutil.copy(ASSETS / 'deploy.php', self.root / 'deploy/deploy.php')
        shutil.copy(self.config_path, self.root / 'deploy/config.json')
        path, digest = self.archive('ssh-release')
        environment = dict(os.environ, PATH=str(bin_dir) + ':' + os.environ['PATH'],
                           DEPLOY_SSH_HOST='test-alias', DEPLOY_ROOT=str(self.root))
        environment.pop('DEPLOY_PHP', None)
        result = subprocess.run(['bash', str(ASSETS / 'deploy-ssh.sh'), path], env=environment, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.current(), 'releases/ssh-release')
        self.assertEqual(hashlib.sha256((self.root / f'incoming/{digest}.zip').read_bytes()).hexdigest(), digest)


if __name__ == '__main__':
    unittest.main(verbosity=2)
