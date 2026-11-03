package main

import (
	"reflect"
	"testing"
)

func TestParseProfileRoutes(t *testing.T) {
	routes, err := parseProfileRoutes("192.168.1.119, 10.0.0.0/8\n192.168.1.119/32")
	if err != nil {
		t.Fatal(err)
	}
	want := []string{"192.168.1.119/32", "10.0.0.0/8"}
	if !reflect.DeepEqual(routes, want) {
		t.Fatalf("got %v, want %v", routes, want)
	}
	if _, err := parseProfileRoutes("0.0.0.0/0"); err == nil {
		t.Fatal("маршрут по умолчанию из профиля должен отклоняться")
	}
}

func TestParseOpenConnectInnerIgnoresDefaultRoute(t *testing.T) {
	inner := parseOpenConnectInner("INC=0.0.0.0/0\nINC=192.168.1.119/32\nDNS=192.168.1.119 8.8.8.8\nDOMAIN=94.103.9.172 corp.example\n")
	if !reflect.DeepEqual(inner.Routes, []string{"192.168.1.119/32"}) {
		t.Fatalf("маршруты: %v", inner.Routes)
	}
	if !reflect.DeepEqual(inner.Domains, []string{"corp.example"}) {
		t.Fatalf("домены: %v", inner.Domains)
	}
}

func TestMaskLength(t *testing.T) {
	if length, ok := maskLength("255.255.255.0"); !ok || length != 24 {
		t.Fatalf("length=%d ok=%v", length, ok)
	}
	if _, ok := maskLength("255.0.255.0"); ok {
		t.Fatal("неканоническая маска принята")
	}
}
