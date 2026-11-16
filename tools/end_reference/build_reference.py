#!/usr/bin/env python3
"""Build a dependency-free Java oracle from selected read-only MCP source methods.

The supplied archive is never modified. Algorithm bodies are extracted verbatim;
only serialization/dependency shells are replaced. Java 17+ is sufficient.
"""
import argparse
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tempfile
import zipfile

HERE = Path(__file__).resolve().parent
PREFIX = 'src/main/java/net/minecraft/'


def body(source, signature):
    start = source.index(signature)
    opening = source.index('{', start)
    depth = 1
    end = opening + 1
    while depth:
        depth += (source[end] == '{') - (source[end] == '}')
        end += 1
    return source[start:end]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--archive', type=Path, required=True, help='Path to your MCP-Reborn-26.2.zip source archive')
    parser.add_argument('--output', type=Path, default=HERE / 'end_noise_fixture.json')
    args = parser.parse_args()
    sources = {}
    with zipfile.ZipFile(args.archive) as archive:
        def get(relative):
            path = PREFIX + relative
            data = archive.read(path)
            sources[path] = {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
            return data.decode('utf-8')
        simplex = get('world/level/levelgen/synth/SimplexNoise.java')
        improved = get('world/level/levelgen/synth/ImprovedNoise.java')
        blended = get('world/level/levelgen/synth/BlendedNoise.java')
        perlin = get('world/level/levelgen/synth/PerlinNoise.java')
        density = get('world/level/levelgen/DensityFunctions.java')
        random = get('world/level/levelgen/LegacyRandomSource.java')
        bitrandom = get('world/level/levelgen/BitRandomSource.java')
        randomsource = get('util/RandomSource.java')
        worldgen = get('world/level/levelgen/WorldgenRandom.java')
        mth = get('util/Mth.java')
        noisechunk = get('world/level/levelgen/NoiseChunk.java')
        get('world/level/biome/TheEndBiomeSource.java')
        jar = zipfile.ZipFile(io.BytesIO(archive.read('projects/mcp/build/mcp/extractServer/output.jar')))
        # Vineflower prints a float literal for a constant actually used as double.
        # Verify the shipped bytecode (l2d / ldc2_w / dmul) before repairing it.
        random_bytecode = jar.read('net/minecraft/world/level/levelgen/BitRandomSource.class')
        with tempfile.TemporaryDirectory(prefix='end-random-bytecode-') as byte_tmp:
            class_path = Path(byte_tmp) / 'BitRandomSource.class'
            class_path.write_bytes(random_bytecode)
            disassembly = subprocess.check_output(['javap', '-c', '-p', str(class_path)], text=True)
        double_section = disassembly[disassembly.index('public default double nextDouble()'):]
        assert 'l2d' in double_section and 'double 1.1102230246251565E-16d' in double_section and 'dmul' in double_section
        bitrandom = bitrandom.replace('combined * 1.110223E-16F', 'combined * 1.1102230246251565E-16D')
        jsons = {}
        for path in ['data/minecraft/worldgen/noise_settings/end.json', 'data/minecraft/worldgen/density_function/end/base_3d_noise.json', 'data/minecraft/worldgen/density_function/end/sloped_cheese.json']:
            data = jar.read(path)
            jsons[path] = {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest(), 'value': json.loads(data)}
    # Exact Java algorithm bodies, stripped only of package imports/annotations.
    simplex = simplex[simplex.index('public class SimplexNoise'):]
    improved = improved[improved.index('public final class ImprovedNoise'):]
    improved = improved[:improved.index('    @VisibleForTesting')].rstrip() + '\n}\n'
    random_methods = [body(bitrandom, signature).replace('default ', 'public ') for signature in [
        'default int nextInt()', 'default int nextInt(final int bound)', 'default long nextLong()',
        'default boolean nextBoolean()', 'default float nextFloat()', 'default double nextDouble()']]
    random_methods.append(body(randomsource, 'default void consumeCount(final int rounds)').replace('default ', 'public '))
    random_class = '''final class LegacyRandomSource implements RandomSource {
      private final java.util.concurrent.atomic.AtomicLong seed = new java.util.concurrent.atomic.AtomicLong();
      LegacyRandomSource(long seed){setSeed(seed);}
      public void setSeed(long value){seed.set((value ^ 25214903917L) & 281474976710655L);}
    ''' + body(random, 'public int next(final int bits)') + '\n' + '\n'.join(random_methods)
    random_class = random_class.replace('throw ThreadingDetector.makeThreadingException("LegacyRandomSource", null);', 'throw new IllegalStateException("concurrent random use");')
    random_class += '\n'.join(body(worldgen, signature) for signature in [
        'public long setDecorationSeed', 'public void setFeatureSeed', 'public void setLargeFeatureSeed', 'public void setLargeFeatureWithSalt']) + '\n}'
    mth_class = 'final class Mth {\n' + '\n'.join(body(mth, signature) for signature in [
        'public static float sqrt', 'public static int floor(final double', 'public static long lfloor',
        'public static float abs', 'public static float clamp', 'public static double clamp',
        'public static double clampedLerp', 'public static double lerp(final double', 'public static double lerp2',
        'public static double lerp3', 'public static double smoothstep', 'public static double smoothstepDerivative']) + '\n}'
    # Legacy Perlin has all amplitudes=1 and octaves -15..0 / -7..0, no skip branch.
    perlin_class = '''final class PerlinNoise {
      private final ImprovedNoise[] noiseLevels;
      PerlinNoise(RandomSource random,int count){
        noiseLevels=new ImprovedNoise[count];
        for(int i=count-1;i>=0;i--) noiseLevels[i]=new ImprovedNoise(random);
      }
    ''' + body(perlin, 'public @Nullable ImprovedNoise getOctaveNoise').replace('@Nullable ', '') + '\n' + body(perlin, 'public static double wrap') + '\n}'
    blend_class = '''final class BlendedNoise {
      private final PerlinNoise minLimitNoise,maxLimitNoise,mainNoise;
      private final double xzMultiplier=684.412*0.25,yMultiplier=684.412*0.25;
      private final double xzFactor=80.0,yFactor=160.0,smearScaleMultiplier=4.0;
      BlendedNoise(RandomSource random){
        minLimitNoise=new PerlinNoise(random,16);
        maxLimitNoise=new PerlinNoise(random,16);
        mainNoise=new PerlinNoise(random,8);
      }
    ''' + body(blended, 'public double compute') + '\n}'
    island_class = 'final class EndIslands {private final SimplexNoise islandNoise;\n'
    island_class += body(density, 'public EndIslandDensityFunction').replace('EndIslandDensityFunction', 'EndIslands')
    island_class += '\n' + body(density, 'private static float getHeightValue').replace('private static', 'public static')
    island_class += '\n' + body(density[density.index('protected static final class EndIslandDensityFunction'):], 'public double compute') + '\n}'
    # Copied interpolation update bodies, then expose their arguments through a shell.
    interpolation_class = '''final class Interpolation {
      double noise000,noise001,noise100,noise101,noise010,noise011,noise110,noise111;
      double valueXZ00,valueXZ10,valueXZ01,valueXZ11,valueZ0,valueZ1,value;
      double compute(double[] n,int gx,int gy,int gz,double fx,double fy,double fz){
        int i=(gx*3+gz)*33+gy;
        noise000=n[i];noise010=n[i+1];noise100=n[i+99];noise110=n[i+100];
        noise001=n[i+33];noise011=n[i+34];noise101=n[i+132];noise111=n[i+133];
        updateForY(fy);updateForX(fx);updateForZ(fz);return value;
      }
    ''' + '\n'.join(body(noisechunk, 'private void ' + signature) for signature in ['updateForY(final double factorY)', 'updateForX(final double factorX)', 'updateForZ(final double factorZ)']) + '\n}'
    # Trusted minimal context interfaces replace the unrelated Minecraft registries.
    shells = '''interface RandomSource {
      int nextInt(); int nextInt(int bound); long nextLong(); boolean nextBoolean();
      float nextFloat(); double nextDouble(); void consumeCount(int rounds);
    }
    interface DensityFunction {interface FunctionContext {int blockX();int blockY();int blockZ();}}
    record Context(int blockX,int blockY,int blockZ) implements DensityFunction.FunctionContext {}
    '''
    runner = (HERE / 'EndNoiseReference.java').read_text()
    with tempfile.TemporaryDirectory(prefix='end-noise-java-') as tmp:
        root = Path(tmp)
        (root / 'EndNoiseReference.java').write_text(runner)
        for name, text in {'SimplexNoise': simplex, 'ImprovedNoise': improved, 'LegacyRandomSource': random_class,
                           'Mth': mth_class, 'PerlinNoise': perlin_class, 'BlendedNoise': blend_class,
                           'EndIslands': island_class, 'Interpolation': interpolation_class, 'Shells': shells}.items():
            (root / (name + '.java')).write_text(text)
        subprocess.run(['javac', '-d', str(root), *map(str, root.glob('*.java'))], check=True)
        generated = subprocess.check_output(['java', '-cp', str(root), 'EndNoiseReference'], text=True)
        fixture = json.loads(generated)
    fixture['evidence'] = {
        'archive_name': args.archive.name,
        'archive_sha256': hashlib.file_digest(args.archive.open('rb'), 'sha256').hexdigest(),
        'source_files': sources,
        'bytecode_correction': {
            'class': 'net/minecraft/world/level/levelgen/BitRandomSource.class',
            'sha256': hashlib.sha256(random_bytecode).hexdigest(),
            'decompiler_expression': 'combined * 1.110223E-16F',
            'verified_expression': 'combined * 1.1102230246251565E-16D',
            'javap': double_section.strip(),
        },
        'json_resources': jsons,
        'harness_sha256': hashlib.sha256((HERE / 'EndNoiseReference.java').read_bytes()).hexdigest(),
        'builder_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
        'method': 'Source method bodies for RNG draws, Simplex, ImprovedNoise, BlendedNoise, EndIslands, Mth, and NoiseChunk interpolation; one nextDouble decompiler literal repaired against shipped bytecode; minimal context/constructor shells; density slides from embedded JSON.',
        'java_version': subprocess.run(['java', '-version'], capture_output=True, text=True, check=True).stderr.strip(),
    }
    args.output.write_text(json.dumps(fixture, indent=2, allow_nan=False) + '\n')
    print(str(args.output))
    print('sha256=' + hashlib.sha256(args.output.read_bytes()).hexdigest())


if __name__ == '__main__':
    main()
