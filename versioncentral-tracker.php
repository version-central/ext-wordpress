<?php

/*
Plugin Name: VersionCentral Tracker
Description: Behalte Deine Updates im Griff.
License: MIT
Version: 1.0.0
Author: VersionCentral
Author URI: https://versioncentral.com
*/

add_action('admin_menu', 'vc_add_admin_menu');
add_action('admin_init', 'vc_settings_init');

function vc_add_admin_menu() {

  add_options_page(
      'VersionCentral',
      'VersionCentral',
      'manage_options',
      'version-central',
      'vc_options_page'
  );

}

function vc_settings_init() {

  register_setting('vc_page', 'versioncentral');

  add_settings_section(
      'vc_page_section',
      __('Information', 'version-central'),
      null,
      'vc_page'
  );

  add_settings_field(
      'vc_api_identifier',
      __('Token', 'version-central'),
      'vc_field_credentials_render',
      'vc_page',
      'vc_page_section'
  );

  add_action(
      'wp_ajax_vc_verify_credentials',
      'vc_verify_credentials_handler'
  );

  add_action(
      'wp_ajax_vc_update_remote_data',
      'vc_update_remote_data_event'
  );

  add_action(
      'vc_update_remote_data_event',
      'vc_update_remote_data_event'
  );

}

function vc_field_credentials_render() {

  $options = get_option('versioncentral');

  echo sprintf(
          '<input type="text" name="versioncentral[api_credentials]" id="vc_api_credentials" value="%s" size="75" />',
          $options['api_credentials']
      )."\n";

}

function vc_request(array $options, array $credentials) {

  $default_args = array(
      'headers' => array(
          'Accept' => 'application/vnd.version-central-v1+json',
          'Authorization' => sprintf('Basic %s', base64_encode($credentials['api_credentials']))
      )
  );

  $http = new WP_Http();
  return $http->request(
      'https://data.versioncentral.com',
      array_merge_recursive($default_args, $options)
  );

}

function vc_verify_credentials_handler() {

  $credentials = array(
      'api_credentials' => $_POST['versioncentral']['api_credentials']
  );

  $res = vc_request(
      array(
          'method' => 'HEAD'
      ),
      $credentials
  );

  header(
      sprintf(
          'HTTP/1.1 %d %s',
          $res['response']['code'],
          $res['response']['message']
      )
  );

  if (intval($res['response']['code']/100) === 2) {
    update_option('versioncentral', $credentials);

    if (!wp_get_schedule('vc_update_remote_data_event')) {
      wp_schedule_event(time(), 'daily', 'vc_update_remote_data_event');
    }
    wp_schedule_single_event(time(), 'vc_update_remote_data_event');

    echo "Verbindung erfolgreich.";
  } else {
    delete_option('versioncentral');
    wp_clear_scheduled_hook('vc_update_remote_data_event');

    echo "Verbindung nicht erfolgreich, bitte prüfe deine API-Daten.";
  }

  wp_die();

}

function vc_options_page() {

  ?>
  <form id="verify-credentials">
    <h1>VersionCentral</h1>

    <div class="notice hidden is-dismissible vc-notice">
      <p></p>
    </div>

    <?php

    if ($nextUpdateAt = wp_next_scheduled('vc_update_remote_data_event')) {
      echo sprintf(
          '<h3>Administration</h3>

        <table class="form-table">
          <tr>
            <th scope="row">Nächstes Update</th>
            <td>%s <a id="force-update">(Update erzwingen)</a></td>
          </tr>
        </table>',
          date('c', $nextUpdateAt)
      );
    }

    settings_fields('vc_page');
    do_settings_sections('vc_page');
    ?>

    <button type="submit" class="button-primary">Token verifizieren</button>

    <script type="text/javascript">

      var vc_valid_credentials = function (response) {
        jQuery(".vc-notice").removeClass("hidden").removeClass("notice-error").addClass("notice-success").find("p").html(response);
      };

      var vc_invalid_credentials = function (response) {
        jQuery(".vc-notice").removeClass("hidden").removeClass("notice-success").addClass("notice-error").find("p").html(response.responseText);
      };

      var vc_force_update = function vc_force_update() {
        jQuery.ajax(
            {
              type: 'POST',
              url: ajaxurl,
              data: {
                action: 'vc_update_remote_data'
              },
              success: function(response) {
                jQuery(".vc-notice").removeClass("hidden").removeClass("notice-error").addClass("notice-success").find("p").html("Update erfolgreich.");
              },
              error: function(response) {
                  var body = JSON.parse(response.responseText);
                  var message = "";

                  body.errors.forEach(function(error) {
                      console.log(error);
                    switch(error.code) {
                        case "application_type_changed":
                            message += "Dieses Token ist bereits von einer anderen Anwendung in Benutzung. Bitte benutzen Sie das korrekte Token für Ihre Anwendung oder erstellen Sie eine neue Anwendung in VersionCentral.<br>";
                            break;
                        default:
                            message += "Ein Fehler ist bei der Übertragung an VersionCentral aufgetreten. Bitte setzen Sie sich mit uns in Verbindung für weitere Informationen und Unterstützung.<br>";
                            break;
                    }
                  });
                jQuery(".vc-notice").removeClass("hidden").removeClass("notice-success").addClass("notice-error").find("p").html(message);
              }
            }
        );
        return false;
      };

      var vc_verify_credentials = function vc_verify_credentials() {
        var form = jQuery(this);
        jQuery.ajax(
            {
              type: 'POST',
              url: ajaxurl,
              data: {
                action: 'vc_verify_credentials',
                versioncentral: {
                  api_credentials: form.find('#vc_api_credentials').val(),
                }
              },
              success: vc_valid_credentials,
              error: vc_invalid_credentials
            }
        );
        return false;
      };

      var vc_ready = function vc_ready($) {
        jQuery('#verify-credentials')
            .on('submit', vc_verify_credentials);

        jQuery('#force-update')
            .on('click', vc_force_update);
      };
      jQuery(document).ready(vc_ready);

    </script>

  </form>
  <?php

}

function vc_update_remote_data_event() {

  include ABSPATH.WPINC.'/version.php';

  $plugins = array_map(
      function($identifier, array $plugin) {
        $plugin = array_change_key_case($plugin, CASE_LOWER);
        return array(
            'identifier' => dirname($identifier),
            'version' => $plugin['version'],
            'active' => is_plugin_active($identifier)
        );
      },
      array_keys(get_plugins()),
      array_values(get_plugins())
  );

  $data = array(
      'application' => array(
          'identifier' => 'wordpress',
          'version' => $wp_version
      ),
      'packages' => $plugins,
      'meta' => array(
          'name' => get_bloginfo('name'),
          'url' => get_site_url()
      )
  );

  $res = vc_request(
      array(
          'method' => 'PUT',
          'headers' => array(
              'Content-Type' => 'application/json',
          ),
          'body' => json_encode($data)
      ),
      get_option('versioncentral')
  );

  header(
      sprintf(
          'HTTP/1.1 %d %s',
          $res['response']['code'],
          $res['response']['message']
      )
  );

  echo $res["body"];

  wp_die();
}
