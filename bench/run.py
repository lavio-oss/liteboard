"""One benchmark run against the isolated "lbtest" stack. Standard library only.

usage: python bench/run.py L0|L1|L2 [--runs N]      (levels from docs/plan.md section 12)
       python bench/run.py --devices N --duration S [--runs N]
env:   BENCH_TOKEN (Sanctum token, control-plane:read/write), BENCH_TENANT, BENCH_PROJECT

Creates devices through the API, drives them from a Linux container, reconciles against PostgreSQL,
prints the plan's report template, appends JSON to bench/results/runs.jsonl and deletes the devices it created.
"""
import argparse
import json
import os
import re
import subprocess
import sys
import threading
import time
import urllib.request
import uuid
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PROJECT_NAME = os.getenv('BENCH_COMPOSE_PROJECT', 'lbtest')
LEVELS = {'L0': (10, 300), 'L1': (100, 600), 'L2': (500, 900)}
API = os.getenv('BENCH_API', 'http://localhost:8800/api')
METRICS_URL = os.getenv('BENCH_METRICS_URL', 'http://localhost:8100/metrics')
PCTL = '0.5,0.95,0.99'


def call(method, url, body=None):
    request = urllib.request.Request(url, method=method, data=None if body is None else json.dumps(body).encode(), headers={
        'Authorization': f'Bearer {os.environ["BENCH_TOKEN"]}', 'Accept': 'application/json', 'Content-Type': 'application/json'})
    with urllib.request.urlopen(request, timeout=60) as response:
        return json.load(response)


def psql(sql):
    return subprocess.run(['docker', 'exec', '-i', f'{PROJECT_NAME}-postgres-1', 'psql', '-U', 'postgres', '-d', 'liteboard', '-Atc', sql],
                          capture_output=True, text=True, check=True).stdout.strip()


def metrics():
    try:
        text = urllib.request.urlopen(METRICS_URL, timeout=5).read().decode()
    except OSError:
        return {}
    return {m.group(1): float(m.group(2)) for m in re.finditer(r'^(\w+(?:\{[^}]*\})?) ([\d.eE+-]+)$', text, re.M)}


def make_devices(count, prefix):
    base = f'{API}/tenants/{os.environ["BENCH_TENANT"]}/projects/{os.environ["BENCH_PROJECT"]}/devices'

    def one(index):
        name = f'{prefix}-{index}'
        device = call('POST', base, {'name': name, 'code': name})['data']
        credentials = call('GET', f'{base}/{device["id"]}/credentials')['data']
        return {'id': device['id'], 'client_id': name, 'username': credentials['access_token'],
                'password': credentials['access_token'], 'topic': device['mqtt_topics']['telemetry']}

    with ThreadPoolExecutor(8) as pool:
        return list(pool.map(one, range(count)))


class Sampler(threading.Thread):
    """docker stats, broker/queue metrics and stored-sample count, every ~10 s."""

    def __init__(self, like):
        super().__init__(daemon=True)
        self.like, self.stop_event = like, threading.Event()
        self.stats, self.series, self.metrics, self.t0 = {}, [], [], time.time()

    def run(self):
        while not self.stop_event.is_set():
            out = subprocess.run(['docker', 'stats', '--no-stream', '--format', '{{json .}}'], capture_output=True, text=True).stdout
            for line in out.splitlines():
                row = json.loads(line)
                if row['Name'].startswith(PROJECT_NAME):
                    mem = row['MemUsage'].split('/')[0].strip()
                    mb = float(mem[:-3]) * (1024 if mem.endswith('GiB') else 1) if mem.endswith(('MiB', 'GiB')) else 0
                    entry = self.stats.setdefault(row['Name'], {'cpu': [], 'mem': []})
                    entry['cpu'].append(float(row['CPUPerc'].rstrip('%')))
                    entry['mem'].append(mb)
            self.series.append((round(time.time() - self.t0), int(psql(f"select count(*) from telemetry_samples where device_id in (select id from devices where code like '{self.like}')") or 0)))
            self.metrics.append(metrics())
            self.stop_event.wait(5)

    def resources(self):
        return {name: {'cpu_avg': round(sum(v['cpu']) / len(v['cpu']), 1), 'cpu_max': max(v['cpu']), 'mem_max_mb': round(max(v['mem']))}
                for name, v in self.stats.items() if v['cpu']}


def percentiles(sql_expr, like):
    row = psql(f"select percentile_cont(array[{PCTL}]) within group (order by v) from ({sql_expr.format(like=like)}) t(v)")
    return [round(float(x), 1) for x in row.strip('{}').split(',')] if row else []


def run_once(devices_count, duration, ramp, tag):
    run_id = uuid.uuid4().hex[:6]
    prefix = f'bench-{run_id}'
    like = f'{prefix}-%'
    created = time.time()
    devices = make_devices(devices_count, prefix)
    tmp = ROOT / 'bench' / 'results'
    tmp.mkdir(exist_ok=True)
    (tmp / 'devices.json').write_text(json.dumps(devices))
    image = subprocess.run(['docker', 'inspect', '-f', '{{.Config.Image}}', f'{PROJECT_NAME}-telemetry-service-1'], capture_output=True, text=True, check=True).stdout.strip()
    before = metrics()
    sampler = Sampler(like)
    sampler.start()
    started = time.time()
    try:
        proc = subprocess.run(['docker', 'run', '--rm', '--network', f'{PROJECT_NAME}_default', '--entrypoint', 'python',
                               '-v', f'{ROOT}/bench:/b', '-v', f'{ROOT}/infrastructure/mosquitto/certs/ca.crt:/ca.crt', image,
                               '/b/generator.py', '/b/results/devices.json', str(duration), 'mosquitto', '8883', str(ramp)],
                              capture_output=True, text=True)
        line = next((l for l in proc.stdout.splitlines() if l.startswith('RESULT')), None)
        if line is None:
            raise RuntimeError(f'generator failed: {proc.stderr[-500:]}')
        gen = json.loads(line[6:])
        drain_start, last = time.time(), -1
        while True:
            stored = int(psql(f"select count(*) from telemetry_samples where device_id in (select id from devices where code like '{like}')"))
            if stored == last:
                break
            last = stored
            time.sleep(3)
        drain = round(time.time() - drain_start - 3, 1)
    finally:
        sampler.stop_event.set()
        sampler.join()
    after = metrics()

    outcomes = dict(row.split('|') for row in psql(
        f"select status, count(*) from telemetry_ingest_outcomes where device_id in (select id from devices where code like '{like}') group by status").splitlines() if row)
    dupes = int(psql(f"select count(*) from (select 1 from telemetry_samples where device_id in (select id from devices where code like '{like}') group by device_id, message_id having count(*) > 1) d"))
    ids = f"select id from devices where code like '{{like}}'"
    e2e = percentiles(
        "select extract(epoch from (o.stages->-1->>'at')::timestamptz - (s.values->>'sent_at')::timestamptz) * 1000 from telemetry_ingest_outcomes o "
        f"join telemetry_samples s on s.device_id = o.device_id and s.message_id = o.message_id where o.status = 'persisted' and o.device_id in ({ids})", like)
    internal = percentiles(
        "select extract(epoch from (stages->-1->>'at')::timestamptz - (stages->0->>'at')::timestamptz) * 1000 from telemetry_ingest_outcomes "
        f"where status = 'persisted' and device_id in ({ids})", like)
    broker_log = subprocess.run(['docker', 'logs', f'{PROJECT_NAME}-mosquitto-1', '--since', str(int(time.time() - started) + 10) + 's'], capture_output=True, text=True)
    log = broker_log.stdout + broker_log.stderr

    # Cleanup: the FKs from samples, identities, outcomes and credentials cascade on delete.
    psql(f"delete from devices where code like '{like}'")

    queue = [m.get('liteboard_broker_store_messages') for m in sampler.metrics if m.get('liteboard_broker_store_messages') is not None]
    result = {
        'tag': tag, 'date': time.strftime('%Y-%m-%d %H:%M'), 'devices': devices_count, 'duration_s': duration, 'rate': 1,
        'connected': gen['connected'], 'connect_fail': gen['connect_fail'], 'connect_s': gen['connect_s'],
        'sent': gen['sent'], 'pubacked': gen['pubacked'], 'stored': stored, 'rejected': int(outcomes.get('rejected', 0)),
        'duplicate_outcomes': int(outcomes.get('duplicate', 0)), 'duplicate_samples': dupes,
        'unexplained_loss': gen['sent'] - stored - int(outcomes.get('rejected', 0)) - int(outcomes.get('duplicate', 0)),
        'throughput_msg_s': round(stored / duration, 1), 'drain_s': drain,
        'e2e_ms_p50_p95_p99': e2e, 'service_ms_p50_p95_p99': internal,
        'stored_series_s_count': sampler.series, 'resources': sampler.resources(),
        'queue_store_messages_max': max(queue) if queue else None,
        'broker_dropped_delta': (after.get('liteboard_broker_publish_dropped_total', 0) - before.get('liteboard_broker_publish_dropped_total', 0)) if after else None,
        'alerts_seen': sorted({k for m in sampler.metrics for k, v in m.items() if k.startswith('liteboard_ingest_alert') and v == 1}),
        'broker_log': {'keepalive_timeout': len(re.findall(r'exceeded timeout', log)), 'not_authorised': len(re.findall(r'not authori[sz]ed', log)),
                       'new_connection': len(re.findall(r'New connection from', log))},
        'seconds_total': round(time.time() - created),
    }
    return result


def report(r, commit):
    return f"""Date / commit:                   {r['date']} / {commit}
Device count / rate / duration:  {r['devices']} / {r['rate']} msg/s / {r['duration_s']} s  (connected {r['connected']}, failed {r['connect_fail']}, connect phase {r['connect_s']} s)
Messages sent / PUBACKed:        {r['sent']} / {r['pubacked']}
Stored / rejected / duplicate:   {r['stored']} / {r['rejected']} / {r['duplicate_outcomes']} (duplicate samples in table: {r['duplicate_samples']})
Unexplained loss (sent - stored - rejected - duplicate): {r['unexplained_loss']}
Throughput (stored):             {r['throughput_msg_s']} msg/s, drain {r['drain_s']} s
End-to-end p50/p95/p99 (ms):     {r['e2e_ms_p50_p95_p99']}   (generator publish -> commit)
Service-internal p50/p95/p99:    {r['service_ms_p50_p95_p99']}   (received -> commit)
Resources (cpu avg/max %, mem MB): {json.dumps(r['resources'])}
Queue (broker stored msgs max) / dropped delta / alerts: {r['queue_store_messages_max']} / {r['broker_dropped_delta']} / {r['alerts_seen']}
Broker log:                      {r['broker_log']}
"""


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('level', nargs='?', choices=LEVELS)
    parser.add_argument('--devices', type=int)
    parser.add_argument('--duration', type=int)
    parser.add_argument('--runs', type=int, default=1)
    parser.add_argument('--ramp', type=float, default=0.02, help='seconds between connection attempts')
    parser.add_argument('--tag', default='')
    args = parser.parse_args()
    devices, duration = (args.devices, args.duration) if args.devices else LEVELS[args.level]
    commit = subprocess.run(['git', 'rev-parse', '--short', 'HEAD'], cwd=ROOT, capture_output=True, text=True).stdout.strip()
    dirty = bool(subprocess.run(['git', 'status', '--porcelain'], cwd=ROOT, capture_output=True, text=True).stdout.strip())
    out = ROOT / 'bench' / 'results'
    out.mkdir(exist_ok=True)
    for index in range(args.runs):
        result = run_once(devices, duration, args.ramp, args.tag or args.level or 'custom')
        result['commit'] = commit + ('+dirty' if dirty else '')
        print(report(result, result['commit']), flush=True)
        with open(out / 'runs.jsonl', 'a') as handle:
            handle.write(json.dumps(result) + '\n')


if __name__ == '__main__':
    sys.exit(main())
