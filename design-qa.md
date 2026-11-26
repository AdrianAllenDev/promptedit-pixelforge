# Night Shift workspace review

The mobile reference is `../design-directions/02-night-shift.png` in the design workspace. It shows the composer above a timeline with one processing generation and one successful image. The reference measures 852 × 1846 pixels for a target viewport of 390 × 844 CSS pixels.

The browser suite exercises registration, quoting, keyboard submission, final billing, failure feedback, and expired images. It checks page overflow at 360, 390, 768, and 1440 CSS pixels. The screenshots are available under `test-results/workspace-<width>.png` after a browser test run and in the CI screenshot artifact.

The automated screenshots show failed and expired results. A visual comparison still needs the reference state with a processing row and a visible successful image. Compare the prompt, selectors, estimate and activity timeline at 390 × 844 before signing off. The workspace’s hold text, wallet and operator report belong to the later wallet rollout and report review.

final result: blocked
