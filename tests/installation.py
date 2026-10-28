"""PHP and the external scheduler must agree on the same JSON configuration."""
import json
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from installation_config import normalize_url, public_base_url
cases = json.loads((Path(__file__).parent / 'fixtures/installation.json').read_text())
for value, expected in cases['valid']:
    assert normalize_url(value) == expected
    assert public_base_url(json.dumps({'public_base_url': value}).encode()) == expected
for value in cases['invalid']:
    try:
        normalize_url(value)
        raise AssertionError('Accepted invalid URL: ' + value)
    except ValueError:
        pass
for value in [b'{}', b'{broken', b'{"public_base_url":"https://new.test","extra":1}', b'{"public_base_url":"https://new.test","previous_base_urls":{}}', b'x' * 16385]:
    try:
        public_base_url(value)
        raise AssertionError('Accepted invalid config')
    except ValueError:
        pass
print('Shared installation configuration validation OK')
