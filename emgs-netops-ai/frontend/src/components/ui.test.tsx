import { render, screen } from "@testing-library/react";
import { Badge, toneFor } from "./ui";
import { AssessmentView } from "./AssessmentView";

describe("status tones", () => {
  it.each([
    ["Successful", "green"], ["Open", "green"], ["Partial packet loss", "amber"], ["No ICMP response", "amber"],
    ["Closed", "red"], ["Expired", "red"], ["DNS resolution failure", "red"], ["Possible Unauthorised Asset", "red"],
    ["Registered and Active", "green"], ["Newly Discovered", "amber"], ["running", "blue"],
  ])("%s -> %s", (status, tone) => expect(toneFor(status)).toBe(tone));

  it("renders a badge", () => {
    render(<Badge>Filtered / timed out</Badge>);
    expect(screen.getByText("Filtered / timed out")).toBeInTheDocument();
  });
});

describe("AssessmentView", () => {
  it("separates confirmed facts from possible causes", () => {
    render(<AssessmentView a={{ summary: "No ICMP reply.", confirmed: ["0/4 replies"], possible_causes: ["ICMP may be blocked"], recommendations: ["Check TCP 443"], source: "rules" }} />);
    expect(screen.getByText("Confirmed")).toBeInTheDocument();
    expect(screen.getByText("Possible causes (not confirmed)")).toBeInTheDocument();
    expect(screen.getByText("ICMP may be blocked")).toBeInTheDocument();
    expect(screen.getByText(/built-in rules/)).toBeInTheDocument();
  });
  it("renders nothing without a summary", () => {
    const { container } = render(<AssessmentView a={{}} />);
    expect(container).toBeEmptyDOMElement();
  });
});
