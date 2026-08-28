from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi"


def read(path: Path) -> str:
    return path.read_text(errors="replace")


def test_codeql_workflow_exists_with_least_privilege_permissions():
    workflow = read(ROOT / ".github/workflows/codeql.yml")
    assert workflow.startswith("name:")
    assert "permissions:" in workflow
    assert "contents: read" in workflow
    assert "security-events: write" in workflow
    assert "macos-latest" not in workflow
    assert "swift" not in workflow
    assert "dependabot" not in workflow.lower()
    assert "javascript-typescript" in workflow
    assert "python" in workflow
    assert "actions" in workflow
    assert "language: php" not in workflow.lower()


def test_codeql_config_ignores_vendor_javascript():
    config = read(ROOT / ".github/codeql/codeql-config.yml")
    assert "**/*.min.js" in config
    assert "archive/**" in config


def test_no_dependabot_auto_pr_config():
    assert not (ROOT / ".github/dependabot.yml").exists()
    assert not (ROOT / ".github/dependabot.yaml").exists()


def test_ipmilan_is_allowlisted_and_shell_escaped():
    text = read(PLUGIN / "include/ipmi_options.php")
    assert "function ipmi_lan_driver" in text
    assert "escapeshellarg($ipmilan)" in text
    assert '-D $ipmilan' not in text
    assert "function ipmi_hex_tokens" in text
    assert "function ipmi_pid_running" in text


def test_fan_sensor_ignore_list_is_shell_escaped():
    helpers = read(PLUGIN / "include/ipmi_helpers.php")
    assert "-R $ignore" not in helpers
    assert "-R '.escapeshellarg($ignore)" in helpers


def test_pid_status_does_not_shell_out_to_proc():
    settings = read(PLUGIN / "include/ipmi_settings.php")
    assert "ipmi_pid_running('/var/run/ipmiseld.pid')" in settings
    assert "ipmi_pid_running('/var/run/ipmifan.pid')" in settings
    assert "shell_exec( \"[ -f /proc" not in settings


def test_daemon_sanitizes_pid_and_ipmi_raw_tokens():
    daemon = read(PLUGIN / "scripts/ipmifan")
    assert "intval(file($lockfile" in daemon
    assert "escapeshellarg((string)$lock_pid)" in daemon
    assert "exec(\"kill $lock_pid\")" not in daemon
    assert "ipmi_hex_tokens" in daemon
    assert "escapeshellarg('/dev/'.$device)" in daemon


def test_event_pages_escape_bmc_fields_before_html_insert():
    escape_js = read(PLUGIN / "js/ipmi-escape.js")
    assert "function ipmiEscapeHtml" in escape_js
    for page in ["IPMIEvents.page", "IPMIArchive.page", "IPMISensors.page", "IPMIDash.page", "IPMIDashmovable.page"]:
        text = read(PLUGIN / page)
        assert "ipmi-escape.js" in text
        assert "ipmiEscapeHtml" in text


def test_php_option_builders_html_escape_sensor_names():
    helpers = read(PLUGIN / "include/ipmi_helpers.php")
    assert "ipmi_h($sensor['Name'])" in helpers
    assert "ipmi_h($temp['Name'])" in helpers
    temp = read(PLUGIN / "include/ipmi_temp.php")
    assert "ipmi_h($disp_name)" in temp
    settings = read(PLUGIN / "IPMISettings.page")
    assert "ipmi_h($board" in settings
