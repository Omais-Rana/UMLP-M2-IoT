/**
 * @file leaderElection.cpp
 **/
#include <iostream>
#include "leaderElectionCode.hpp"

using namespace std;
using namespace LeaderElection;

int main(int argc, char **argv) {
    try {
        createSimulator(argc, argv, LeaderElectionCode::buildNewBlockCode);
        getSimulator()->printInfo();
        BaseSimulator::getWorld()->printInfo();
        deleteSimulator();
    } catch(std::exception const& e) {
        cerr << "Uncaught exception: " << e.what();
    }
    return 0;
}
