package cepcore

import "net/netip"

// vpnRoutes — маршруты VPN: 0.0.0.0/0 без исключений цепочки. VpnService.Builder.excludeRoute есть только
// с Android 13, поэтому дополнение строится здесь и работает на любой версии: исключённые сети просто не попадают в VPN.
func vpnRoutes(bypass []string) []string {
	var excl []netip.Prefix
	for _, s := range bypass {
		if p, err := netip.ParsePrefix(s); err == nil {
			excl = append(excl, p.Masked())
		}
	}
	var out []string
	var walk func(p netip.Prefix)
	walk = func(p netip.Prefix) {
		overlaps := false
		for _, e := range excl {
			if e.Bits() <= p.Bits() && e.Contains(p.Addr()) {
				return // вся сеть p исключена
			}
			if p.Overlaps(e) {
				overlaps = true
			}
		}
		if !overlaps {
			out = append(out, p.String())
			return
		}
		lo := netip.PrefixFrom(p.Addr(), p.Bits()+1)
		a := p.Addr().As4()
		bit := p.Bits()
		a[bit/8] |= 0x80 >> (bit % 8)
		hi := netip.PrefixFrom(netip.AddrFrom4(a), p.Bits()+1)
		walk(lo)
		walk(hi)
	}
	walk(netip.MustParsePrefix("0.0.0.0/0"))
	return out
}
