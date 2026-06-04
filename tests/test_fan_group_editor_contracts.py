from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HELPERS = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/include/ipmi_helpers.php"
PAGE = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/IPMIFans.page"
SETTINGS = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/include/ipmi_settings_fan.php"
DAEMON = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/scripts/ipmifan"
PROBE = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/scripts/ipmi2json"


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
    assert "<option value=\"\">Select All</option>',get_fan_channel_options()" not in helpers
    assert "<option value=\"\">Select All</option>',get_sensor_group_options()" not in helpers
    assert "class=\"fan-group-hdd-row" in helpers
    assert "get_hdd_options_for_fan('')" in helpers
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


def test_group_editor_spindown_details_hide_unless_override_sensor_selected_and_primary_hdd_is_selected():
    helpers = read(HELPERS)
    page = read(PAGE)
    assert "fan-group-spindown-row" in helpers
    assert "fan-group-spindown-detail-row" in helpers
    assert "syncFanGroupSpindownVisibility" in page
    assert "#fan-group-temphdd" in page
    assert "hasHddSensor" in page
    assert "spindownValue !== '0'" in page
    assert ".prop('disabled', !hasHddSensor)" in page
    assert ".fan-group-spindown-detail-row" in page


def test_redundant_global_hard_drives_to_poll_selector_removed_from_fan_page():
    page = read(PAGE)
    assert "Hard Drives to Poll" not in page
    assert "select-drives" not in page
    assert "initDriveDropdown" not in page
    assert "HARDDRIVES" not in page
    assert "Hard drives for this fan group" in read(HELPERS)


def test_configured_groups_table_has_edit_and_remove_actions():
    helpers = read(HELPERS)
    page = read(PAGE)
    assert "function get_configured_fan_group_table" in helpers
    assert "Configured fan groups" in helpers
    assert "fan-group-table" in helpers
    assert "editFanGroup(" in helpers
    assert "removeFanGroup(" in helpers
    assert "data-fans" in helpers
    assert "data-sensors" in helpers
    assert "data-temphdd" in helpers
    assert "data-temphio" in helpers
    assert "#fan-group-temphdd').val(row.data('temphdd')" in page
    assert "#fan-group-temphio').val(row.data('temphio')" in page


def test_advanced_toggle_resynchronizes_spindown_rows_after_showing_all_advanced_settings():
    page = read(PAGE)
    assert "toggleFanCTRL();\n        $('.fanctrl-temp').trigger('change');" in page
    assert "syncFanGroupSpindownVisibility();" in page


def test_fan_group_fan_selector_top_fan_does_not_select_all():
    page = read(PAGE)
    assert "function DDCheckList(Select,Values,firstItemChecksAll)" in page
    assert "firstItemChecksAll: (firstItemChecksAll !== false)" in page
    assert "DDCheckList('#fan-group-fans', null, false)" in page
    assert "DDCheckList('#fan-group-sensors', null, false)" in page
    assert "DDCheckList('#fan-group-hdds', null, true)" in page


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


def test_probe_discovers_shared_independent_channels_and_excludes_zero_rpm_fans():
    probe = read(PROBE)
    helpers = read(HELPERS)
    assert "probe_fan_channels" in probe
    assert "detect_fan_response" in probe
    assert "exclude_zero_rpm_fans" in probe
    assert "continue to show 0 RPM" in probe
    assert "channel_to_fans" in probe
    assert "detected_fan_map" in helpers
    assert "get_detected_fan_control_target" in helpers
    assert "detected shared channel" in helpers
