package main

import "testing"

func TestXrayConfigReality(t *testing.T) {
	link, err := parseProxyLink("vless://uuid@example.com:443?security=reality&pbk=key&sid=short&type=grpc&serviceName=service")
	if err != nil {
		t.Fatal(err)
	}
	config, err := xrayConfig(link, "192.0.2.1", 20800)
	if err != nil {
		t.Fatal(err)
	}
	outbounds := config["outbounds"].([]any)
	outbound := outbounds[0].(map[string]any)
	stream := outbound["streamSettings"].(map[string]any)
	if stream["network"] != "grpc" || stream["realitySettings"] == nil || stream["grpcSettings"] == nil {
		t.Fatalf("неожиданный streamSettings: %#v", stream)
	}
}

func TestSingBoxConfigPinsInterfaceAndDisablesAutoRoute(t *testing.T) {
	config := singBoxConfig("ceps0", "172.19.0.1/30", socksOutbound(20800))
	inbound := config["inbounds"].([]any)[0].(map[string]any)
	if inbound["interface_name"] != "ceps0" || inbound["auto_route"] != false {
		t.Fatalf("небезопасный TUN: %#v", inbound)
	}
	route := config["route"].(map[string]any)
	if route["auto_detect_interface"] != false {
		t.Fatalf("auto_detect_interface должен быть выключен")
	}
}

func TestPortOverridePreservesDefaultHostnameSNI(t *testing.T) {
	vlessLink, err := parseProxyLink("vless://uuid@cdn.example.com:8443?security=tls&type=tcp")
	if err != nil {
		t.Fatal(err)
	}
	cfg, err := xrayConfigWithPort(vlessLink, "127.0.0.1", 43210, 20801)
	if err != nil {
		t.Fatal(err)
	}
	outbound := cfg["outbounds"].([]any)[0].(map[string]any)
	vnext := outbound["settings"].(map[string]any)["vnext"].([]any)[0].(map[string]any)
	if vnext["address"] != "127.0.0.1" || vnext["port"] != 43210 {
		t.Fatalf("неожиданный vnext: %#v", vnext)
	}
	tlsSettings := outbound["streamSettings"].(map[string]any)["tlsSettings"].(map[string]any)
	if tlsSettings["serverName"] != "cdn.example.com" {
		t.Fatalf("ожидался SNI cdn.example.com, получено %#v", tlsSettings)
	}

	hy2Link, err := parseProxyLink("hy2://pass@hy.example.com:443?insecure=1")
	if err != nil {
		t.Fatal(err)
	}
	sbOutbound, err := singBoxOutboundWithPort(hy2Link, "127.0.0.1", 43211)
	if err != nil {
		t.Fatal(err)
	}
	if sbOutbound["server"] != "127.0.0.1" || sbOutbound["server_port"] != 43211 {
		t.Fatalf("неожиданный outbound: %#v", sbOutbound)
	}
	if sbOutbound["tls"].(map[string]any)["server_name"] != "hy.example.com" {
		t.Fatalf("ожидался SNI hy.example.com, получено %#v", sbOutbound["tls"])
	}
}
