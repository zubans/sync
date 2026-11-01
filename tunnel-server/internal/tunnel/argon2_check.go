// Чистая реализация BLAKE2b (RFC 7693) и Argon2id (RFC 9106, v0x13) на стандартной библиотеке Go
// без внешних зависимостей, обеспечивающая побитовую совместимость ключей между Ubuntu-сервером и Android-клиентом.
package tunnel

import (
	"encoding/binary"
	"math/bits"
	"sync"
)

var blake2bIV = [8]uint64{
	0x6a09e667f3bcc908, 0xbb67ae8584caa73b,
	0x3c6ef372fe94f82b, 0xa54ff53a5f1d36f1,
	0x510e527fade682d1, 0x9b05688c2b3e6c1f,
	0x1f83d9abfb41bd6b, 0x5be0cd19137e2179,
}

var blake2bSigma = [12][16]uint8{
	{0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15},
	{14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3},
	{11, 8, 12, 0, 5, 2, 15, 13, 10, 14, 3, 6, 7, 1, 9, 4},
	{7, 9, 3, 1, 13, 12, 11, 14, 2, 6, 5, 10, 4, 0, 15, 8},
	{9, 0, 5, 7, 2, 4, 10, 15, 14, 1, 11, 12, 6, 8, 3, 13},
	{2, 12, 6, 10, 0, 11, 8, 3, 4, 13, 7, 5, 15, 14, 1, 9},
	{12, 5, 1, 15, 14, 13, 4, 10, 0, 7, 6, 3, 9, 2, 8, 11},
	{13, 11, 7, 14, 12, 1, 3, 9, 5, 0, 15, 4, 8, 6, 2, 10},
	{6, 15, 14, 9, 11, 3, 0, 8, 12, 2, 13, 7, 1, 4, 10, 5},
	{10, 2, 8, 4, 7, 6, 1, 5, 15, 11, 9, 14, 3, 12, 13, 0},
	{0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15},
	{14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3},
}

type blake2bHash struct {
	h      [8]uint64
	t      [2]uint64
	buf    [128]byte
	bufLen int
	outLen int
}

func newBlake2b(outLen int) *blake2bHash {
	b := &blake2bHash{outLen: outLen}
	b.h = blake2bIV
	b.h[0] ^= 0x01010000 ^ uint64(outLen)
	return b
}

func (b *blake2bHash) Write(p []byte) (int, error) {
	total := len(p)
	for len(p) > 0 {
		if b.bufLen == 128 {
			b.t[0] += 128
			if b.t[0] < 128 {
				b.t[1]++
			}
			b.compress(&b.buf, false)
			b.bufLen = 0
		}
		n := copy(b.buf[b.bufLen:], p)
		b.bufLen += n
		p = p[n:]
	}
	return total, nil
}

func (b *blake2bHash) Sum(out []byte) []byte {
	c := *b
	c.t[0] += uint64(c.bufLen)
	if c.t[0] < uint64(c.bufLen) {
		c.t[1]++
	}
	for i := c.bufLen; i < 128; i++ {
		c.buf[i] = 0
	}
	c.compress(&c.buf, true)
	var digest [64]byte
	for i := 0; i < 8; i++ {
		binary.LittleEndian.PutUint64(digest[i*8:(i+1)*8], c.h[i])
	}
	return append(out, digest[:c.outLen]...)
}

func (b *blake2bHash) compress(block *[128]byte, last bool) {
	var v [16]uint64
	var m [16]uint64
	for i := 0; i < 16; i++ {
		m[i] = binary.LittleEndian.Uint64(block[i*8 : (i+1)*8])
	}
	for i := 0; i < 8; i++ {
		v[i] = b.h[i]
		v[i+8] = blake2bIV[i]
	}
	v[12] ^= b.t[0]
	v[13] ^= b.t[1]
	if last {
		v[14] = ^v[14]
	}

	g := func(a, bIdx, c, d int, x, y uint64) {
		v[a] = v[a] + v[bIdx] + x
		v[d] = bits.RotateLeft64(v[d]^v[a], -32)
		v[c] = v[c] + v[d]
		v[bIdx] = bits.RotateLeft64(v[bIdx]^v[c], -24)
		v[a] = v[a] + v[bIdx] + y
		v[d] = bits.RotateLeft64(v[d]^v[a], -16)
		v[c] = v[c] + v[d]
		v[bIdx] = bits.RotateLeft64(v[bIdx]^v[c], -63)
	}

	for r := 0; r < 12; r++ {
		s := blake2bSigma[r]
		g(0, 4, 8, 12, m[s[0]], m[s[1]])
		g(1, 5, 9, 13, m[s[2]], m[s[3]])
		g(2, 6, 10, 14, m[s[4]], m[s[5]])
		g(3, 7, 11, 15, m[s[6]], m[s[7]])
		g(0, 5, 10, 15, m[s[8]], m[s[9]])
		g(1, 6, 11, 12, m[s[10]], m[s[11]])
		g(2, 7, 8, 13, m[s[12]], m[s[13]])
		g(3, 4, 9, 14, m[s[14]], m[s[15]])
	}

	for i := 0; i < 8; i++ {
		b.h[i] ^= v[i] ^ v[i+8]
	}
}

func blake2b512(data []byte) [64]byte {
	h := newBlake2b(64)
	_, _ = h.Write(data)
	var out [64]byte
	res := h.Sum(out[:0])
	copy(out[:], res)
	return out
}

// Параметры Argon2id (RFC 9106, версия 0x13, гибридный режим = 2 (Argon2id)).
const (
	argon2SyncPoints = 4
	argon2Version    = 0x13
	argon2ModeID     = 2
)

type argon2Block [128]uint64

func argon2IDKey(password, salt []byte, time, memory uint32, threads uint8, keyLen uint32) []byte {
	if threads == 0 {
		threads = 1
	}
	lanes := uint32(threads)
	if memory < 2*argon2SyncPoints*lanes {
		memory = 2 * argon2SyncPoints * lanes
	}
	segmentLength := memory / (lanes * argon2SyncPoints)
	laneLength := segmentLength * argon2SyncPoints
	memoryBlocks := laneLength * lanes

	h0 := argon2InitialHash(password, salt, time, memory, lanes, keyLen)
	B := make([]argon2Block, memoryBlocks)
	argon2InitBlocks(&h0, B, lanes, laneLength)
	argon2ProcessBlocks(B, time, lanes, laneLength, segmentLength)
	return argon2ExtractKey(B, lanes, laneLength, keyLen)
}

func argon2InitialHash(password, salt []byte, time, memory, lanes, keyLen uint32) [72]byte {
	h := newBlake2b(64)
	var tmp [4]byte
	put32 := func(v uint32) {
		binary.LittleEndian.PutUint32(tmp[:], v)
		_, _ = h.Write(tmp[:])
	}
	put32(lanes)
	put32(keyLen)
	put32(memory)
	put32(time)
	put32(argon2Version)
	put32(argon2ModeID)
	put32(uint32(len(password)))
	_, _ = h.Write(password)
	put32(uint32(len(salt)))
	_, _ = h.Write(salt)
	put32(0) // secret len
	put32(0) // ad len

	var h0 [72]byte
	h.Sum(h0[:0])
	return h0
}

func argon2HashVariable(out []byte, in []byte) {
	var lenBuf [4]byte
	binary.LittleEndian.PutUint32(lenBuf[:], uint32(len(out)))
	if len(out) <= 64 {
		h := newBlake2b(len(out))
		_, _ = h.Write(lenBuf[:])
		_, _ = h.Write(in)
		h.Sum(out[:0])
		return
	}
	h := newBlake2b(64)
	_, _ = h.Write(lenBuf[:])
	_, _ = h.Write(in)
	var buf [64]byte
	h.Sum(buf[:0])
	copy(out, buf[:32])
	out = out[32:]

	for len(out) > 64 {
		buf = blake2b512(buf[:])
		copy(out, buf[:32])
		out = out[32:]
	}
	lastH := newBlake2b(len(out))
	_, _ = lastH.Write(buf[:])
	lastH.Sum(out[:0])
}

func argon2InitBlocks(h0 *[72]byte, B []argon2Block, lanes, laneLength uint32) {
	var blockBytes [1024]byte
	for lane := uint32(0); lane < lanes; lane++ {
		j := lane * laneLength
		binary.LittleEndian.PutUint32(h0[68:72], lane)

		binary.LittleEndian.PutUint32(h0[64:68], 0)
		argon2HashVariable(blockBytes[:], h0[:])
		for k := 0; k < 128; k++ {
			B[j+0][k] = binary.LittleEndian.Uint64(blockBytes[k*8 : (k+1)*8])
		}

		binary.LittleEndian.PutUint32(h0[64:68], 1)
		argon2HashVariable(blockBytes[:], h0[:])
		for k := 0; k < 128; k++ {
			B[j+1][k] = binary.LittleEndian.Uint64(blockBytes[k*8 : (k+1)*8])
		}
	}
}

func argon2ProcessBlocks(B []argon2Block, time, lanes, laneLength, segmentLength uint32) {
	iterations := int(segmentLength)
	processSegment := func(n, slice, lane uint32, wg *sync.WaitGroup) {
		if wg != nil {
			defer wg.Done()
		}
		var addresses, zero argon2Block
		var input [128]uint64
		useAddresses := (n == 0) && (slice < argon2SyncPoints/2)
		if useAddresses {
			input[0] = uint64(n)
			input[1] = uint64(lane)
			input[2] = uint64(slice)
			input[3] = uint64(len(B))
			input[4] = uint64(time)
			input[5] = uint64(argon2ModeID)
		}

		index := 0
		if n == 0 && slice == 0 {
			index = 2
			if useAddresses {
				input[6]++
				argon2Compression(&addresses, &zero, (*argon2Block)(&input))
				argon2Compression(&addresses, &addresses, &zero)
			}
		}

		offset := lane*laneLength + slice*segmentLength + uint32(index)
		for index < iterations {
			prev := offset - 1
			if index == 0 && slice == 0 {
				prev += laneLength
			}
			var random uint64
			if useAddresses {
				if index%128 == 0 {
					input[6]++
					argon2Compression(&addresses, &zero, (*argon2Block)(&input))
					argon2Compression(&addresses, &addresses, &zero)
				}
				random = addresses[index%128]
			} else {
				random = B[prev][0]
			}
			newOffset := argon2IndexAlpha(random, lanes, segmentLength, int(lanes), index, n, slice, lane)
			argon2CompressionXOR(&B[offset], &B[prev], &B[newOffset])
			index++
			offset++
		}
	}

	var wg sync.WaitGroup
	for n := uint32(0); n < time; n++ {
		for slice := uint32(0); slice < argon2SyncPoints; slice++ {
			for lane := uint32(0); lane < lanes; lane++ {
				if lanes == 1 {
					processSegment(n, slice, lane, nil)
				} else {
					wg.Add(1)
					go processSegment(n, slice, lane, &wg)
				}
			}
			if lanes > 1 {
				wg.Wait()
			}
		}
	}
}

func argon2IndexAlpha(rand uint64, lanes, segmentLength uint32, threads, index int, pass, slice, lane uint32) uint32 {
	refLane := uint32(rand>>32) % lanes
	if pass == 0 && slice == 0 {
		refLane = lane
	}
	m := 3 * segmentLength
	s := ((slice + 1) % argon2SyncPoints) * segmentLength
	if lane == refLane {
		m += uint32(index)
	}
	if pass == 0 {
		m = slice * segmentLength
		s = 0
		if slice == 0 || lane == refLane {
			m += uint32(index)
		}
	}
	if index == 0 || lane == refLane {
		m--
	}
	x := uint64(uint32(rand))
	x = (x * x) >> 32
	x = (uint64(m) * x) >> 32
	return refLane*(segmentLength*argon2SyncPoints) + (s+m-1-uint32(x))%(segmentLength*argon2SyncPoints)
}

func argon2ExtractKey(B []argon2Block, lanes, laneLength, keyLen uint32) []byte {
	for lane := uint32(0); lane < lanes-1; lane++ {
		for i, v := range B[(lane*laneLength)+laneLength-1] {
			B[len(B)-1][i] ^= v
		}
	}
	var block [1024]byte
	for i, v := range B[len(B)-1] {
		binary.LittleEndian.PutUint64(block[i*8:(i+1)*8], v)
	}
	key := make([]byte, keyLen)
	argon2HashVariable(key, block[:])
	return key
}

func blamka(x, y uint64) uint64 {
	m := uint64(uint32(x)) * uint64(uint32(y))
	return x + y + 2*m
}

func gRound(a, b, c, d *uint64) {
	*a = blamka(*a, *b)
	*d = bits.RotateLeft64(*d^*a, -32)
	*c = blamka(*c, *d)
	*b = bits.RotateLeft64(*b^*c, -24)
	*a = blamka(*a, *b)
	*d = bits.RotateLeft64(*d^*a, -16)
	*c = blamka(*c, *d)
	*b = bits.RotateLeft64(*b^*c, -63)
}

func blake2bRound(v0, v1, v2, v3, v4, v5, v6, v7, v8, v9, v10, v11, v12, v13, v14, v15 *uint64) {
	gRound(v0, v4, v8, v12)
	gRound(v1, v5, v9, v13)
	gRound(v2, v6, v10, v14)
	gRound(v3, v7, v11, v15)
	gRound(v0, v5, v10, v15)
	gRound(v1, v6, v11, v12)
	gRound(v2, v7, v8, v13)
	gRound(v3, v4, v9, v14)
}

func argon2Compression(out, in1, in2 *argon2Block) {
	var r argon2Block
	for i := range r {
		r[i] = in1[i] ^ in2[i]
		out[i] = r[i]
	}
	for i := 0; i < 128; i += 16 {
		blake2bRound(
			&r[i+0], &r[i+1], &r[i+2], &r[i+3],
			&r[i+4], &r[i+5], &r[i+6], &r[i+7],
			&r[i+8], &r[i+9], &r[i+10], &r[i+11],
			&r[i+12], &r[i+13], &r[i+14], &r[i+15],
		)
	}
	for i := 0; i < 16; i += 2 {
		blake2bRound(
			&r[i+0], &r[i+1], &r[i+16], &r[i+17],
			&r[i+32], &r[i+33], &r[i+48], &r[i+49],
			&r[i+64], &r[i+65], &r[i+80], &r[i+81],
			&r[i+96], &r[i+97], &r[i+112], &r[i+113],
		)
	}
	for i := range r {
		out[i] ^= r[i]
	}
}

func argon2CompressionXOR(out, in1, in2 *argon2Block) {
	var r argon2Block
	for i := range r {
		r[i] = in1[i] ^ in2[i]
		out[i] ^= r[i]
	}
	for i := 0; i < 128; i += 16 {
		blake2bRound(
			&r[i+0], &r[i+1], &r[i+2], &r[i+3],
			&r[i+4], &r[i+5], &r[i+6], &r[i+7],
			&r[i+8], &r[i+9], &r[i+10], &r[i+11],
			&r[i+12], &r[i+13], &r[i+14], &r[i+15],
		)
	}
	for i := 0; i < 16; i += 2 {
		blake2bRound(
			&r[i+0], &r[i+1], &r[i+16], &r[i+17],
			&r[i+32], &r[i+33], &r[i+48], &r[i+49],
			&r[i+64], &r[i+65], &r[i+80], &r[i+81],
			&r[i+96], &r[i+97], &r[i+112], &r[i+113],
		)
	}
	for i := range r {
		out[i] ^= r[i]
	}
}
