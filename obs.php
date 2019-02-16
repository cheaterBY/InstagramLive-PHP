<?php /** @noinspection PhpComposerExtensionStubsInspection */

require_once 'utils.php';

class ObsHelper
{
    public $obs_path;
    public $service_path;
    public $settings_path;
    public $profile_name;
    public $service_state;
    public $settings_state;
    public $attempted_service_save;
    public $attempted_settings_save;
    public $autoStream;
    public $forceSlobs;
    public $slobsPresent;

    /**
     * Checks for OBS installation and detects service file locations.
     * @param bool $autoStream Automatically starts streaming in OBS if true.
     * @param bool $disable Disables path check if true.
     * @param bool $forceStreamlabs Forces streamlabs obs over normal obs.
     */
    public function __construct(bool $autoStream, bool $disable, bool $forceStreamlabs)
    {
        $this->service_state = null;
        $this->settings_state = null;
        $this->attempted_service_save = false;
        $this->attempted_settings_save = false;
        $this->autoStream = $autoStream;
        $this->forceSlobs = $forceStreamlabs;
        $this->slobsPresent = false;

        if (!Utils::isWindows() || $disable) {
            $this->obs_path = null;
            return;
        }

        clearstatcache();
        if (@file_exists("C:/Program Files/obs-studio/") && !$forceStreamlabs) {
            $this->obs_path = "C:/Program Files/obs-studio/";
        } elseif (@file_exists("C:/Program Files (x86)/obs-studio/") && !$forceStreamlabs) {
            $this->obs_path = "C:/Program Files (x86)/obs-studio/";
        } elseif (@file_exists("C:/Program Files/Streamlabs OBS/")) {
            $this->obs_path = "C:/Program Files/Streamlabs OBS/";
            $this->slobsPresent = true;
        } elseif (@file_exists("C:/Program Files (x86)/Streamlabs OBS/")) {
            $this->obs_path = "C:/Program Files (x86)/Streamlabs OBS/";
            $this->slobsPresent = true;
        } else {
            $this->obs_path = null; //OBS's path could not be found, the script will disable OBS integration.
            return;
        }

        if ($this->slobsPresent) {
            Utils::log("OBS Integration: StreamLabs-OBS Detected! This script is not able to automatically start streaming; It is recommended you use regular OBS to have all features.");
            $this->service_path = getenv("appdata") . '\slobs-client\service.json';
            $this->settings_path = getenv("appdata") . '\slobs-client\basic.ini';
            return;
        }

        $profiles = $dirs = array_filter(glob(getenv("appdata") . "\obs-studio\basic\profiles\*"), 'is_dir');
        $profile = null;
        if (count($profiles) === 0) {
            $this->obs_path = null;
            return;
        } else if (count($profiles) === 1) {
            $profile = $profiles[0];
        } else {
            Utils::log("OBS Integration: Multi-Profile environment detected! Please select your current OBS profile.");
            $profileIndex = 0;
            foreach ($profiles as $curProfile) {
                Utils::log("[$profileIndex] - " . str_replace(getenv("appdata") . "\obs-studio\basic\profiles\\", '', $curProfile));
                $profileIndex++;
            }
            print "OBS Integration: Type your Profile ID from the above selection...\n>";
            $handle = fopen("php://stdin", "r");
            $profileIndex = trim(fgets($handle));
            @$profile = $profiles[$profileIndex];
            if ($profile === null) {
                Utils::log("OBS Integration: Invalid Profile Selection!");
                $this->obs_path = null;
                return;
            }
        }
        $this->service_path = "$profile\service.json";
        $this->settings_path = "$profile\basic.ini";
        $this->profile_name = trim(str_replace(getenv("appdata") . "\obs-studio\basic\profiles\\", '', $profile));
    }

    /**
     * Creates backup of current service.json state, if exists.
     */
    public function saveServiceState()
    {
        $this->attempted_service_save = true;
        clearstatcache();
        if (@file_exists($this->service_path)) {
            $this->service_state = json_decode(@file_get_contents($this->service_path), true);
            return;
        }
        $this->service_state = null;
    }

    /**
     * Creates backup of current basic.ini state, if exists.
     */
    public function saveSettingsState()
    {
        $this->attempted_settings_save = true;
        clearstatcache();
        if (@file_exists($this->settings_path)) {
            $this->settings_state = @file_get_contents($this->settings_path);
            return;
        }
        $this->settings_state = null;
    }

    /**
     * Resets service.json state to before script was run.
     * Deletes the file if none existed beforehand.
     */
    public function resetServiceState()
    {
        clearstatcache();
        if (@file_exists($this->service_path) && $this->service_state == null) {
            @unlink($this->service_path);
            return;
        }
        @file_put_contents($this->service_path, json_encode($this->service_state, JSON_PRETTY_PRINT));
    }

    /**
     * Resets basic.ini state to before script was run.
     * Deletes the file if non existed beforehand.
     */
    public function resetSettingsState()
    {
        clearstatcache();
        if (@file_exists($this->settings_path) && $this->settings_state == null) {
            @unlink($this->settings_path);
            return;
        }
        @file_put_contents($this->settings_path, $this->settings_state);
    }

    /**
     * Updates the service.json file with streaming url and key.
     * @param string $uri The rmtp uri.
     * @param string $key The stream key.
     */
    public function setServiceState(string $uri, string $key)
    {
        @file_put_contents($this->service_path, json_encode([
            'settings' => [
                'key' => $key,
                'server' => $uri
            ],
            'type' => 'rtmp_custom'
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Updates the basic.ini with the proper stream configuration.
     */
    public function updateSettingsState()
    {
        $handle = fopen($this->settings_path, "r");
        $newLines = '';
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (Utils::startsWith($line, "[")) {
                    $currentSection = str_replace(']', '', str_replace('[', '', $line));
                }

                $line = $this->searchAndReplaceSetting($line, 'BaseCX', '720');
                $line = $this->searchAndReplaceSetting($line, 'BaseCY', '1280');
                $line = $this->searchAndReplaceSetting($line, 'OutputCX', '720');
                $line = $this->searchAndReplaceSetting($line, 'OutputCY', '1280');
                if ($currentSection = 'SimpleOutput') {
                    $line = $this->searchAndReplaceSetting($line, 'VBitrate', '4000');
                }

                $newLines = $newLines . $line;
            }
            fclose($handle);
        } else {
            Utils::log("OBS Integration: Unable to modify settings!");
        }

        @file_put_contents($this->settings_path, $newLines);
    }

    /**
     * Searches for setting in obs basic.ini and replaces its value.
     * @param string $haystack The line to search.
     * @param string $setting The setting to search for.
     * @param string $value The replacement value for the setting.
     * @return string The modified line.
     */
    private function searchAndReplaceSetting($haystack, $setting, $value)
    {
        if (Utils::startsWith($haystack, $setting)) {
            $haystack = "$setting=$value\n";
        }
        return $haystack;
    }

    /**
     * Kills OBS, if running.
     * @return bool Returns true if successful.
     */
    public function killOBS(): bool
    {
        if ($this->slobsPresent) {
            return strpos(shell_exec("taskkill /IM \"crash-handler-process.exe\" /F && taskkill /IM \"crashpad_handler.exe\" /F && taskkill /IM \"Streamlabs OBS.exe\" /F && taskkill /IM obs64.exe /F"), "SUCCESS");
        }
        return strpos(shell_exec("taskkill /IM obs64.exe /F"), "SUCCESS");
    }

    /**
     * Starts OBS with startstreaming flag.
     */
    public function spawnOBS()
    {
        clearstatcache();
        if ($this->slobsPresent) {
            pclose(popen("cd \"$this->obs_path\" && start /B \"Streamlabs OBS.exe\"", "r"));
            return true;
        }
        pclose(popen("cd \"$this->obs_path" . "bin/64bit\" && start /B obs64.exe" . ($this->autoStream ? " --startstreaming" : "") . " --profile $this->profile_name", "r"));
        return true;
    }

    /**
     * Checks to see if OBS is running.
     * @return bool Returns true if obs is running.
     */
    public function isObsRunning(): bool
    {
        $res = shell_exec("tasklist /FI \"IMAGENAME eq obs64.exe\" 2>NUL | find /I /N \"obs64.exe\">NUL && if \"%ERRORLEVEL%\"==\"0\" echo running");
        if (strcmp($res, "") !== 0) {
            return true;
        }
        return false;
    }

    /**
     * Waits for OBS to launch.
     * @return bool Returns true if obs launches within 15 seconds.
     */
    public function waitForOBS(): bool
    {
        $attempts = 0;
        while ($attempts != 15) {
            if ($this->isObsRunning()) {
                return true;
            }
            $attempts++;
            sleep(1);
        }
        return false;
    }
}