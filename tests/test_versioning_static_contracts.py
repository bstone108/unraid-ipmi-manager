import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION = "2026.06.03.05"


def read(path: Path) -> str:
    return path.read_text(errors="replace")


def test_plugin_manifests_use_zfs_style_main_version():
    for manifest in [ROOT / "plugin/ipmi.plg", ROOT / "plugin/ipmi-dev.plg"]:
        text = read(manifest)
        assert f'<!ENTITY version   "{VERSION}">' in text
        assert f"###{VERSION}" in text
        assert '<!ENTITY plgNAME   "&name;-&version;-x86_64-1">' in text
        assert not re.search(r'<!ENTITY version\s+"[^"]*\.t\d+">', text)


def test_mkpkg_uses_central_time_zfs_style_main_numeric_versions():
    text = read(ROOT / "source/mkpkg")
    assert "TZ=America/Chicago" in text
    assert 'date +"%Y.%m.%d"' in text
    assert '"%s.%02d"' in text
    assert "t%02d" not in text
    assert re.search(r"for\s+build\s+in\s+\$\(seq\s+1\s+99\)", text)


def test_release_artifacts_for_current_version_exist():
    package = ROOT / f"archive/ipmi-{VERSION}-x86_64-1.txz"
    checksum = ROOT / f"archive/ipmi-{VERSION}-x86_64-1.md5"
    assert package.exists()
    assert checksum.exists()
    assert f"ipmi-{VERSION}-x86_64-1.txz" in read(checksum)
