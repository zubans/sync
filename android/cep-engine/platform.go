package cepcore

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"net/netip"
	"os"
	"sync"
	"syscall"

	"github.com/sagernet/sing-box/adapter"
	C "github.com/sagernet/sing-box/constant"
	"github.com/sagernet/sing-box/option"
	tun "github.com/sagernet/sing-tun"
	"github.com/sagernet/sing/common/control"
	"github.com/sagernet/sing/common/logger"
	"github.com/sagernet/sing/common/x/list"
)

// platform — adapter.PlatformInterface sing-box поверх VpnService. Устроен как libbox,
// но без командного сервера и всего, что CEP не нужно.
type platform struct {
	p              Platform
	settings       TunSettings
	networkManager adapter.NetworkManager

	access       sync.Mutex
	defaultIface *control.Interface
	callbacks    list.List[tun.DefaultInterfaceUpdateCallback]
	myInterfaces []string
	myAddress    []netip.Addr
}

var _ adapter.PlatformInterface = (*platform)(nil)

func (w *platform) Initialize(networkManager adapter.NetworkManager) error {
	w.networkManager = networkManager
	return nil
}

func (w *platform) UsePlatformAutoDetectInterfaceControl() bool { return true }

func (w *platform) AutoDetectInterfaceControl(fd int) error {
	if !w.p.Protect(int32(fd)) {
		return fmt.Errorf("VpnService.protect не сработал")
	}
	return nil
}

func (w *platform) UsePlatformInterface() bool { return true }

func (w *platform) OpenInterface(options *tun.Options, _ option.TunPlatformOptions) (tun.Tun, error) {
	settings, _ := json.Marshal(w.settings)
	fd, err := w.p.OpenTun(string(settings))
	if err != nil {
		return nil, err
	}
	// дескриптор остаётся у ParcelFileDescriptor в Kotlin, sing-box работает с копией
	dupFd, err := syscall.Dup(int(fd))
	if err != nil {
		return nil, fmt.Errorf("dup tun: %w", err)
	}
	options.FileDescriptor = dupFd
	if options.Name == "" {
		options.Name = "tun0"
	}
	options.InterfaceMonitor.RegisterMyInterface(options.Name)
	w.access.Lock()
	for _, prefix := range options.Inet4Address {
		w.myAddress = append(w.myAddress, prefix.Addr())
	}
	w.access.Unlock()
	return tun.New(*options)
}

func (w *platform) ProcessPlatformOptions(option.TunPlatformOptions) error { return nil }

func (w *platform) UsePlatformDefaultInterfaceMonitor() bool { return true }

func (w *platform) CreateDefaultInterfaceMonitor(logger.Logger) tun.DefaultInterfaceMonitor {
	return &monitor{w}
}

// netInterface — элемент JSON из Platform.Interfaces().
type netInterface struct {
	Name      string   `json:"name"`
	Index     int      `json:"index"`
	MTU       int      `json:"mtu"`
	Addresses []string `json:"addresses"`
	DNS       []string `json:"dns"`
	Type      string   `json:"type"`
	Metered   bool     `json:"metered"`
	Default   bool     `json:"default"`
}

func (w *platform) readInterfaces() []netInterface {
	var list []netInterface
	_ = json.Unmarshal([]byte(w.p.Interfaces()), &list)
	return list
}

func (w *platform) UsePlatformNetworkInterfaces() bool { return true }

func (w *platform) NetworkInterfaces() ([]adapter.NetworkInterface, error) {
	var result []adapter.NetworkInterface
	for _, it := range w.readInterfaces() {
		var addrs []netip.Prefix
		for _, a := range it.Addresses {
			if p, err := netip.ParsePrefix(a); err == nil {
				addrs = append(addrs, p)
			}
		}
		t, ok := C.StringToInterfaceType[it.Type]
		if !ok {
			t = C.InterfaceTypeOther
		}
		result = append(result, adapter.NetworkInterface{
			Interface: control.Interface{
				Index:     it.Index,
				MTU:       it.MTU,
				Name:      it.Name,
				Addresses: addrs,
				Flags:     net.FlagUp | net.FlagRunning,
			},
			Type:       t,
			DNSServers: it.DNS,
			Expensive:  it.Metered,
		})
	}
	return result, nil
}

func (w *platform) updateDefaultInterface(name string, index int) {
	if w.networkManager != nil {
		_ = w.networkManager.UpdateInterfaces()
	}
	w.access.Lock()
	if index < 0 {
		w.defaultIface = nil
	} else {
		var iface *control.Interface
		if w.networkManager != nil {
			iface, _ = w.networkManager.InterfaceFinder().ByIndex(index)
		}
		if iface == nil {
			iface = &control.Interface{Name: name, Index: index}
		}
		if w.defaultIface != nil && w.defaultIface.Name == iface.Name && w.defaultIface.Index == iface.Index {
			w.access.Unlock()
			return
		}
		w.defaultIface = iface
	}
	current := w.defaultIface
	callbacks := w.callbacks.Array()
	w.access.Unlock()
	for _, cb := range callbacks {
		cb(current, 0)
	}
}

func (w *platform) UnderNetworkExtension() bool              { return false }
func (w *platform) NetworkExtensionIncludeAllNetworks() bool { return false }
func (w *platform) ClearDNSCache()                           {}
func (w *platform) RequestPermissionForWIFIState() error     { return nil }
func (w *platform) UsePlatformWIFIMonitor() bool             { return false }
func (w *platform) ReadWIFIState(context.Context) adapter.WIFIState {
	return adapter.WIFIState{}
}
func (w *platform) UsePlatformConnectionOwnerFinder() bool { return false }
func (w *platform) FindConnectionOwner(*adapter.FindConnectionOwnerRequest) (*adapter.ConnectionOwner, error) {
	return nil, os.ErrInvalid
}
func (w *platform) UsePlatformNotification() bool                             { return false }
func (w *platform) SendNotification(*adapter.Notification) error              { return nil }
func (w *platform) CancelNotification(string, int32) error                    { return nil }
func (w *platform) UsePlatformNeighborResolver() bool                         { return false }
func (w *platform) StartNeighborMonitor(adapter.NeighborUpdateListener) error { return os.ErrInvalid }
func (w *platform) CloseNeighborMonitor(adapter.NeighborUpdateListener) error { return nil }
func (w *platform) UsePlatformShell() bool                                    { return false }
func (w *platform) CheckPlatformShell() error                                 { return os.ErrInvalid }
func (w *platform) OpenShellSession(*adapter.PlatformUser, string, []string, string, int32, int32) (adapter.ShellSession, error) {
	return nil, os.ErrInvalid
}
func (w *platform) LookupUser(string) (*adapter.PlatformUser, error) { return nil, os.ErrInvalid }
func (w *platform) LookupSFTPServer() (string, error)                { return "", os.ErrInvalid }
func (w *platform) ReadSystemSSHHostKey() ([]byte, error)            { return nil, os.ErrInvalid }
func (w *platform) TailscaleHostname() string                        { return "" }
func (w *platform) UsePlatformBridge() bool                          { return false }
func (w *platform) CreateBridge(adapter.BridgeOptions) (adapter.BridgeSession, error) {
	return nil, os.ErrInvalid
}

func (w *platform) MyInterfaceAddress() []netip.Addr {
	w.access.Lock()
	defer w.access.Unlock()
	return w.myAddress
}

// monitor — основной интерфейс устройства (Wi-Fi или мобильная сеть), о смене сообщает Kotlin.
type monitor struct{ *platform }

func (m *monitor) Start() error {
	for _, it := range m.readInterfaces() {
		if it.Default {
			m.updateDefaultInterface(it.Name, it.Index)
			break
		}
	}
	return nil
}

func (m *monitor) Close() error { return nil }

func (m *monitor) DefaultInterface() *control.Interface {
	m.access.Lock()
	defer m.access.Unlock()
	return m.defaultIface
}

func (m *monitor) OverrideAndroidVPN() bool { return false }
func (m *monitor) AndroidVPNEnabled() bool  { return false }

func (m *monitor) RegisterCallback(cb tun.DefaultInterfaceUpdateCallback) *list.Element[tun.DefaultInterfaceUpdateCallback] {
	m.access.Lock()
	defer m.access.Unlock()
	return m.callbacks.PushBack(cb)
}

func (m *monitor) UnregisterCallback(e *list.Element[tun.DefaultInterfaceUpdateCallback]) {
	m.access.Lock()
	defer m.access.Unlock()
	m.callbacks.Remove(e)
}

func (m *monitor) RegisterMyInterface(name string) {
	m.access.Lock()
	defer m.access.Unlock()
	m.myInterfaces = append(m.myInterfaces, name)
}

func (m *monitor) MyInterfaces() []string {
	m.access.Lock()
	defer m.access.Unlock()
	return m.myInterfaces
}
