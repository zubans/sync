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
