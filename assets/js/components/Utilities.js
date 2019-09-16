class Utilities {
    /**
     * Returns the parameters passed to the script
     * @param {String} script_name The name of the script
     * @returns {{}}
     */
    static getParams(script_name) {

        // Find all script tags
        var scripts = document.getElementsByTagName('script');

        // Look through them trying to find ourselves
        for (let i = 0; i < scripts.length; i++) {
            if (scripts[i].src.indexOf('/' + script_name) > -1) {
                // Get an array of key=value strings of params
                let pa = scripts[i].src.split('?').pop().split('&');

                // Split each key=value into array, the construct js object
                let p = {};
                for (let j = 0; j < pa.length; j++) {
                    let kv = pa[j].split('=');
                    p[kv[0]] = kv[1];
                }
                return p;
            }
        }

        // No scripts match
        return {};
    }
}

export default Utilities;
