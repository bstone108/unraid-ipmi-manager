from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HELPERS = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/include/ipmi_helpers.php"
PAGE = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/IPMIFans.page"
SETTINGS = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/include/ipmi_settings_fan.php"
DAEMON = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/scripts/ipmifan"


def read(path: Path) -> str:
    return path.read_text(errors="replace")


def test_page_uses_group_editor_above_configured_groups_table_instead_of_per_fan_blocks():
    page = read(PAGE)
    assert "get_fan_group_editor" in page
    assert "get_configured_fan_group_table" in page
    assert page.index("get_fan_group_editor") < page.index("get_configured_fan_group_table")
    assert "<?get_fanctrl_options();?>" not in page
    assert "prepareFanGroupSubmit" in page
    assert "editFanGroup" in page
    assert "removeFanGroup" in page


def test_group_editor_has_multi_selects_for_fans_sensors_and_hdd_drives():
    helpers = read(HELPERS)
    assert "function get_fan_group_editor" in helpers
    assert "id=\"fan-group-fans\"" in helpers
    assert "id=\"fan-group-sensors\"" in helpers
    assert "id=\"fan-group-hdds\"" in helpers
    assert "FANS_" in helpers
    assert "SENSORS_" in helpers
    assert "HDDINCLUDE_" in helpers
    for label in [
        "High temperature threshold",
        "Low temperature threshold",
        "Fan speed maximum",
        "Fan speed minimum",
        "HDD Spundown Temperature sensor",
    ]:
        assert label in helpers


def test_configured_groups_table_has_edit_and_remove_actions():
    helpers = read(HELPERS)
    assert "function get_configured_fan_group_table" in helpers
    assert "Configured fan groups" in helpers
    assert "fan-group-table" in helpers
    assert "editFanGroup(" in helpers
    assert "removeFanGroup(" in helpers
    assert "data-fans" in helpers
    assert "data-sensors" in helpers


def test_supermicro_defaults_do_not_assume_fan1234_shared_channel():
    settings = read(SETTINGS)
    supermicro = settings.split("case  'Supermicro':", 1)[1].split("case 'Dell':", 1)[0]
    for fan in ["FAN1", "FAN2", "FAN3", "FAN4", "FANA", "FANB"]:
        assert f"'{fan}'" in supermicro
    assert "'FAN1' => '00'" in supermicro
    assert "'FAN2' => '01'" in supermicro
    assert "'FAN3' => '02'" in supermicro
    assert "'FAN4' => '03'" in supermicro
    assert "'FAN1234'" not in supermicro


def test_daemon_runs_configured_groups_and_collapses_only_duplicate_checked_targets():
    daemon = read(DAEMON)
    assert "configured_fan_groups" in daemon
    assert "FANGROUPS" in daemon
    assert "SENSORS_" in daemon
    assert "foreach($group['targets'] as $target_fan => $value)" in daemon
    assert "collapse_selected_fans_by_target" in daemon
    assert "duplicate raw target" in daemon
    assert "HDDINCLUDE_" in daemon
