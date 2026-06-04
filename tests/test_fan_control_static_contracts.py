from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SETTINGS_FAN = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/include/ipmi_settings_fan.php"
HELPERS = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/include/ipmi_helpers.php"
IPMIFAN = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/scripts/ipmifan"
FANS_PAGE = ROOT / "source/ipmi/usr/local/emhttp/plugins/ipmi/IPMIFans.page"


def read(path: Path) -> str:
    return path.read_text(errors="replace")


def test_supermicro_default_targets_keep_individual_fans_and_do_not_collapse_to_original_groups():
    text = read(SETTINGS_FAN)
    supermicro_section = text.split("case  'Supermicro':", 1)[1].split("case 'Dell':", 1)[0]
    for fan in ["FAN1", "FAN2", "FAN3", "FAN4", "FANA", "FANB"]:
        assert f"'{fan}'" in supermicro_section
    assert "ipmi_group_shared_fan_channels" not in text
    assert "'FAN1234' => '00'" not in supermicro_section
    assert "'FANAB' => '01'" not in supermicro_section
    assert "$board_json['Supermicro']['fans'] = ipmi_group_shared_fan_channels" not in supermicro_section


def test_package_description_does_not_advertise_old_github_link():
    slack_desc = read(ROOT / "source/ipmi/install/slack-desc")
    assert "https://github.com/dmacias72/unRAID-plugins" not in slack_desc
    assert "IPMI unRAID Plugin" in slack_desc
    assert "allows you to view your system sensors" in slack_desc


def test_fan_ui_keeps_autodetected_fans_visible_and_labels_shared_bmc_channels():
    helpers = read(HELPERS)
    assert "normalize_fan_control_name" in helpers
    assert "get_shared_fan_channel_peers" in helpers
    assert "shared_control_seen" not in helpers
    assert "fan-shared-channel" in helpers
    assert "Shared channel:" in helpers
    assert "fanctrl-drive-select" in helpers
    assert "fanctrl-drive-hidden" in helpers
    assert "HDDINCLUDE_" in helpers
    assert "get_hdd_options_for_fan" in helpers


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
