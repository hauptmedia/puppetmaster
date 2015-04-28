<?php
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $nodeConfigs = extract_node_configs();

    if(!array_key_exists('mac', $_GET)) {
        throw new \Exception("Missing mac");
    }

	$nodes = array_values(array_filter($nodeConfigs, function($entry) use ($_GET) {
		return $entry['mac'] == $_GET['mac'];
	}));

	if(count($nodes) != 1) {
	        header('HTTP/1.1 404 Not Found');
        	exit;
	}

    $clusterConfig = array(
        'etcd-peers' => extract_etcd_peers($nodeConfigs)
    );

	$nodeConfig = $nodes[0];

    $destCloudConfig = array();

    foreach($nodeConfig['features'] as $feature) {
        if(!file_exists("../features/" . $feature . ".php")) {
            throw new \Exception("Unkwnown feature: " . $feature);
        }

        $featureFn          = require("../features/" . $feature . ".php");
        $featureCloudConfig = call_user_func($featureFn, $clusterConfig, $nodeConfig);

        $destCloudConfig = array_merge_recursive(
            $destCloudConfig,
            $featureCloudConfig
        );
    }

    $dumper = new Dumper();
    $cloudConfigFileContent = "#cloud-config\n";
    $cloudConfigFileContent .= $dumper->dump($destCloudConfig, 6);

    //Validate generated file with coreos-cloudinit
    $tmpFileName = tempnam("/tmp", "cloud-config.yml");
    file_put_contents($tmpFileName, $cloudConfigFileContent);
    exec("/usr/local/bin/coreos-cloudinit -validate --from-file=".escapeshellarg($tmpFileName), $output, $ret);
    unlink($tmpFileName);
    
    if ($ret != 0) {
        throw new \Exception("coreos-cloudinit validation failed:\n\n" . implode("\n", $output));
        
    }
    
} catch (\Exception $e) {
	header('HTTP/1.1 500 Internal server error');
    header("Content-Type: text/plain");
    print $e->getMessage();
	exit;
}

header("Content-Type: text/plain");

if(array_key_exists('format', $_GET) && $_GET['format'] == 'sh') {
    function array_flatten($array) {
        $return = array();

        foreach ($array as $key => $value) {
            if (is_array($value)){
                
                foreach (array_flatten($value) as $subKey => $subValue) {
                 $return[$key . "_" . $subKey] = $subValue;
                    
                }
            }
            else {
                $return[$key] = $value;
            }
        }
        return $return;

    }

    foreach(array_flatten($destCloudConfig) as $key => $value) {
        printf("%s=%s\n", str_replace("-", "_", strtoupper($key)), escapeshellarg($value));
    }
    
} else {
    print($cloudConfigFileContent);    
}

/**
 * Extracts the etcd peers list from the specified nodeConfigs
 * @param $nodeConfigs
 */
function extract_etcd_peers($nodeConfigs) {
    $etcdPeers = "";


    return $etcdPeers;
/*
    //collect etcd peers from cluster configuration
    foreach($nodeConfigs as $nodeConfig) {
        if(in_array('etcd2', $enabledFeatures)) {
            print_r($nodeConfig['etcd2']);
        }
    }

*/
}

/**
 * Extracts the node configuration from the cluster-config.yml file.
 * It automatically merges the nodeConfig with the cluster wide configuration
 * @return array
 * @throws Exception
 */
function extract_node_configs() {
    $yaml           = new Parser();
    $fileContents   = @file_get_contents('../var/cluster-config.yml');

    if(!$fileContents) {
        throw new \Exception("Could not find var/cluster-config.yml");
    }

    $yamlContent    = $yaml->parse($fileContents);
    $clusterConfig  = $yamlContent['cluster'];

    $nodes          = $clusterConfig['nodes'];
    unset($clusterConfig['nodes']);

    $nodeConfigs = array();
    foreach($nodes as $nodeConfig) {
        foreach($clusterConfig as $key => $value) {

            if(array_key_exists($key, $nodeConfig)) {
                $nodeConfig[$key] = array_merge($value, $nodeConfig[$key]);
            } else {
               $nodeConfig[$key] = $value;
            }
        }

        $nodeConfigs[] = $nodeConfig;
    }

    return $nodeConfigs;
}
?>



