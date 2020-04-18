<h3>API Addon Settings </h3>
<p>Read the <a href="https://simple-membership-plugin.com/simple-membership-api-creating-member-account-using-http-post-request/" target="_blank">usage documentation</a> to learn how to use the API.</p>
<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">Enable API</th>
            <td><input type="checkbox" <?php echo $enable_api; ?> name="swpm-addon-enable-api" value="checked='checked'" />
                <p class="description">Enable/disable API.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">API Key</th>
            <td>
                <input name="swpm-addon-api-key" type="text" size="60" value="<?php echo $api_key; ?>"/>
                <p class="description">Your API key.</p>
            </td>
        </tr>
    </tbody>
</table>
