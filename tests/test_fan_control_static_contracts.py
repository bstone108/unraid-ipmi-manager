from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SETTINGS_FAN = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/include/ipmi_settings_fan.php"
HELPERS = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/include/ipmi_helpers.php"
IPMIFAN = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/scripts/ipmifan"
FANS_PAGE = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/IPMIFans.page"


def read(path: Path) -> str:
    return path.read_text(errors="replace")


def test_supermicro_default_targets_are_split_and_include_fan_b():
    text = read(SETTINGS_FAN)
    supermicro_section = text.split("case  'Supermicro':", 1)[1].split("case 'Dell':", 1)[0]
    assert "'FAN1234'" not in supermicro_section
    for fan in ["FAN1", "FAN2", "FAN3", "FAN4", "FANA", "FANB"]:
        assert f"'{fan}'" in text
    assert "'FAN1' => '00'" in text
    assert "'FAN4' => '00'" in text
    assert "'FANA' => '01'" in text
    assert "'FANB' => '01'" in text


def test_fan_ui_keeps_autodetected_fans_individual_and_allows_per_fan_drive_selection():
    helpers = read(HELPERS)
    assert "normalize_fan_control_name" in helpers
    assert "fanctrl-drive-select" in helpers
    assert "fanctrl-drive-hidden" in helpers
    assert "HDDINCLUDE_" in helpers
    assert "get_hdd_options_for_fan" in helpers
    assert "FAN1234'" not in helpers


def test_fan_page_initializes_per_fan_drive_dropdowns():
    page = read(FANS_PAGE)
    assert "initFanDriveDropdowns" in page
    assert ".fanctrl-drive-select" in page
    assert "data('hidden')" in page


def test_daemon_uses_per_fan_drive_temperature_and_max_pwm_per_shared_bmc_target():
    text = read(IPMIFAN)
    assert "get_highest_temp_for_fan" in text
    assert "HDDINCLUDE_" in text
    assert "pending_pwm_by_target" in text
    assert "max_pwm_by_target" in text
    assert "current_pwm_by_target" in text
    # The daemon may have several rules mapped to the same BMC channel; it must aggregate
    # by raw target so the safest/highest requested PWM wins instead of whichever rule runs last.
    assert "max($max_pwm_by_target[$target_key], $pwm_numeric)" in text
